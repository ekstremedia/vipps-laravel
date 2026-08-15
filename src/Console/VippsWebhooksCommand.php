<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Nesthus\Vipps\Vipps;
use Nesthus\Vipps\Webhooks\Webhook;
use Nesthus\Vipps\Webhooks\WebhooksApi;

/**
 * Interactive management of Vipps webhook subscriptions:
 *
 *     php artisan vipps:webhooks register [--url=…] [--events=… --events=…]
 *     php artisan vipps:webhooks list
 *     php artisan vipps:webhooks delete --id=… [--force]
 *
 * register is the ONLY moment Vipps ever reveals a webhook's signing secret
 * (listing returns id/url/events; there is no reveal endpoint), so this
 * command prints it exactly once, inside an unmissable warning block, and
 * deliberately never logs or stores it anywhere — persisting it is the
 * operator's job (VIPPS_WEBHOOK_SECRET in .env), because an artisan command
 * writing secrets into logs or files would be a leak, not a convenience.
 */
final class VippsWebhooksCommand extends Command
{
    /**
     * The ten recurring event types, mirroring what the package's webhook
     * module maps to Laravel events ({@see \Nesthus\Vipps\Laravel\Events\EventMap} —
     * a parity test keeps the two identical). Kept as a public constant so
     * apps can reference the exact set this command registers by default.
     */
    public const DEFAULT_EVENTS = [
        'recurring.agreement-activated.v1',
        'recurring.agreement-rejected.v1',
        'recurring.agreement-stopped.v1',
        'recurring.agreement-expired.v1',
        'recurring.charge-reserved.v1',
        'recurring.charge-captured.v1',
        'recurring.charge-canceled.v1',
        'recurring.charge-failed.v1',
        'recurring.charge-creation-failed.v1',
        'recurring.charge-refunded.v1',
    ];

    /**
     * Container key that overrides how this command obtains the WebhooksApi.
     *
     * Why a binding instead of constructor injection or mocking: the SDK's
     * Vipps and WebhooksApi classes are both final, so neither can be mocked
     * or subclassed. Transport, however, is an interface — tests bind a REAL
     * WebhooksApi wrapping a recording fake Transport under this key, which
     * exercises the SDK's actual request/response mapping instead of stubbing
     * it away. Production never binds the key and falls through to the shared
     * Vipps singleton the service provider registered.
     */
    public const WEBHOOKS_API_BINDING = 'vipps.webhooks-api';

    protected $signature = 'vipps:webhooks
        {action : register|list|delete}
        {--url= : Callback URL to register; defaults to the named route vipps.webhooks (register)}
        {--events=* : Event type to subscribe, repeatable; defaults to the ten recurring.* types (register)}
        {--id= : Webhook id to delete (delete)}
        {--force : Skip the delete confirmation, for scripted runs (delete)}';

    protected $description = 'Register, list or delete Vipps webhook subscriptions for the configured sales unit';

    public function handle(): int
    {
        /** @var string $action */
        $action = $this->argument('action');

        return match ($action) {
            'register' => $this->runRegister(),
            'list' => $this->runList(),
            'delete' => $this->runDelete(),
            default => $this->usageFailure(
                sprintf('Unknown action [%s]. Valid actions: register, list, delete.', $action),
            ),
        };
    }

    private function runRegister(): int
    {
        $url = $this->resolveCallbackUrl();

        if ($url === null) {
            $this->error('No callback URL to register: pass --url=https://… or add the receiver route first.');
            $this->line('The receiver is one line in a stateless routes file (routes/api.php):');
            $this->line("    Route::vippsWebhooks('/vipps/webhooks');");
            $this->line('Its route name (vipps.webhooks) is what this command resolves when --url is omitted.');

            return self::INVALID;
        }

        // Rejected BEFORE the API call: Vipps only accepts public HTTPS
        // callbacks, and an http:// registration (typically a dev app's
        // http://localhost route resolved by the --url fallback) would fail
        // only later, as undeliverable webhooks with nothing in any log.
        if (! str_starts_with($url, 'https://')) {
            $this->error(sprintf(
                'Refusing to register [%s]: Vipps only accepts public HTTPS callback URLs. '
                . 'Pass --url=https://… or set the app URL so the vipps.webhooks route resolves to https.',
                $url,
            ));

            return self::INVALID;
        }

        $events = $this->requestedEvents();

        // A generated idempotency key is normally banned — the SDK insists the
        // caller persists one next to its own record BEFORE the request goes
        // out, so a retry after a crash reuses it. This is the one acceptable
        // exception: an interactive admin command with no ledger row of its
        // own, where "the caller" is a human who sees the outcome immediately
        // and re-runs deliberately (getting a fresh registration, on purpose).
        $registered = $this->webhooksApi()->register($url, $events, (string) Str::uuid());

        $this->info('Webhook registered.');
        $this->line('  id:     ' . $registered->id);
        $this->line('  url:    ' . $url);
        $this->line('  events: ' . implode(', ', $events));
        $this->newLine();

        // Printed exactly once and NEVER logged or stored: this block is the
        // entire handoff of the secret. Vipps will not re-show it — if the
        // operator scrolls past, the only recovery is delete + re-register.
        $this->alert('WEBHOOK SECRET — SHOWN ONLY ONCE, COPY IT NOW');
        $this->line('  VIPPS_WEBHOOK_SECRET=' . $registered->secret());
        $this->newLine();
        $this->warn('Vipps never re-shows this secret (listing returns id/url/events only).');
        $this->warn('Put it in your .env as VIPPS_WEBHOOK_SECRET immediately — signature');
        $this->warn('validation of every incoming delivery depends on it.');

        return self::SUCCESS;
    }

    private function runList(): int
    {
        $webhooks = $this->webhooksApi()->all();

        if ($webhooks === []) {
            $this->info('No webhooks are registered for this sales unit.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'URL', 'Events'],
            array_map(
                // One event per line: the default set alone is ten types,
                // and a comma-joined cell would blow past any terminal width.
                static fn(Webhook $webhook): array => [
                    $webhook->id,
                    $webhook->url,
                    implode("\n", $webhook->events),
                ],
                $webhooks,
            ),
        );

        return self::SUCCESS;
    }

    private function runDelete(): int
    {
        $id = $this->option('id');

        if (! is_string($id) || $id === '') {
            return $this->usageFailure('The delete action requires --id=<webhook-id>. Run `vipps:webhooks list` to find it.');
        }

        // Confirmation is not ceremony: deletion is irreversible (the signing
        // secret dies with the registration) and stops deliveries immediately.
        // --force skips it for scripted runs, where confirm() would silently
        // default to "no".
        $question = sprintf('Delete webhook [%s]? Deliveries stop immediately and its signing secret becomes useless.', $id);

        if (! $this->option('force') && ! $this->confirm($question)) {
            $this->comment('Aborted — nothing deleted.');

            // FAILURE, not SUCCESS: a non-interactive run auto-declines this
            // confirmation, and exit 0 here would tell a script the webhook
            // was deleted when nothing happened. Scripts that mean it pass
            // --force.
            return self::FAILURE;
        }

        $this->webhooksApi()->delete($id, (string) Str::uuid());

        $this->info(sprintf('Webhook [%s] deleted.', $id));

        return self::SUCCESS;
    }

    /**
     * --url wins; otherwise the named route registered by the
     * Route::vippsWebhooks() macro is resolved to an absolute URL, so the
     * common case ("register the receiver this app already exposes") needs
     * no arguments at all. Returns null when neither exists — the caller
     * prints guidance, because silently registering a wrong URL would fail
     * only later, as undeliverable webhooks with nothing in any log.
     */
    private function resolveCallbackUrl(): ?string
    {
        $url = $this->option('url');

        if (is_string($url) && $url !== '') {
            return $url;
        }

        /** @var Router $router */
        $router = $this->laravel->make('router');

        if (! $router->has('vipps.webhooks')) {
            return null;
        }

        /** @var UrlGenerator $urlGenerator */
        $urlGenerator = $this->laravel->make('url');

        return $urlGenerator->route('vipps.webhooks');
    }

    /**
     * @return list<string>
     */
    private function requestedEvents(): array
    {
        $option = $this->option('events');

        $events = [];

        foreach (is_array($option) ? $option : [] as $event) {
            if (is_string($event) && $event !== '') {
                $events[] = $event;
            }
        }

        return $events === [] ? self::DEFAULT_EVENTS : $events;
    }

    /**
     * See WEBHOOKS_API_BINDING for why this indirection exists at all.
     */
    private function webhooksApi(): WebhooksApi
    {
        if ($this->laravel->bound(self::WEBHOOKS_API_BINDING)) {
            /** @var WebhooksApi */
            return $this->laravel->make(self::WEBHOOKS_API_BINDING);
        }

        return $this->laravel->make(Vipps::class)->webhooks();
    }

    private function usageFailure(string $message): int
    {
        $this->error($message);

        // INVALID (exit code 2) is Symfony's convention for "the command was
        // used wrongly", as opposed to FAILURE (1, "the work itself failed").
        return self::INVALID;
    }
}
