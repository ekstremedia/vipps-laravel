<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Nesthus\Vipps\Http\ApiResponse;
use Nesthus\Vipps\Http\Transport;
use Nesthus\Vipps\Laravel\Console\VippsWebhooksCommand;
use Nesthus\Vipps\Webhooks\WebhooksApi;

/**
 * Recording fake at the SDK's Transport seam. The SDK's Vipps and WebhooksApi
 * classes are both final, so neither can be mocked — instead the command
 * resolves its WebhooksApi through the WEBHOOKS_API_BINDING container key,
 * and these tests bind a REAL WebhooksApi wrapping this fake. That way the
 * SDK's own request-building and response-parsing runs for real; only the
 * wire is faked.
 */
final class FakeVippsWebhookTransport implements Transport
{
    /** @var list<array{method: string, path: string, json: array<string, mixed>|null, idempotencyKey: string|null}> */
    public array $requests = [];

    /** @var list<ApiResponse> */
    private array $queue = [];

    public function queueResponse(ApiResponse $response): void
    {
        $this->queue[] = $response;
    }

    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
        ?string $idempotencyKey = null,
    ): ApiResponse {
        $this->requests[] = [
            'method' => $method,
            'path' => $path,
            'json' => $json,
            'idempotencyKey' => $idempotencyKey,
        ];

        return array_shift($this->queue) ?? new ApiResponse(200, []);
    }
}

/**
 * Kept in the container (not as a dynamic test-case property) so Pest
 * closures can reach it without tripping PHP 8.2+'s dynamic-property
 * deprecation on the PHPUnit TestCase.
 */
function fakeVippsTransport(): FakeVippsWebhookTransport
{
    /** @var FakeVippsWebhookTransport */
    return app('test.vipps-fake-transport');
}

beforeEach(function () {
    $transport = new FakeVippsWebhookTransport();

    app()->instance('test.vipps-fake-transport', $transport);
    app()->instance(VippsWebhooksCommand::WEBHOOKS_API_BINDING, new WebhooksApi($transport));
});

describe('register', function () {
    it('registers with --url, prints the id, and shows the secret exactly once inside a warning block', function () {
        fakeVippsTransport()->queueResponse(new ApiResponse(201, [
            'id' => 'wh-123',
            'secret' => 'super-secret-value',
        ]));

        $exit = Artisan::call('vipps:webhooks', [
            'action' => 'register',
            '--url' => 'https://example.test/hooks',
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('wh-123')
            ->toContain('WEBHOOK SECRET — SHOWN ONLY ONCE, COPY IT NOW')
            ->toContain('VIPPS_WEBHOOK_SECRET=super-secret-value');

        // "Shown exactly once" is literal: the secret must not repeat
        // elsewhere in the output, where a second copy could end up in a
        // narrower terminal scrollback or a partial copy-paste.
        expect(substr_count($output, 'super-secret-value'))->toBe(1);
    });

    it('defaults to the ten recurring event types and sends a UUID idempotency key', function () {
        fakeVippsTransport()->queueResponse(new ApiResponse(201, ['id' => 'wh-1', 'secret' => 's']));

        Artisan::call('vipps:webhooks', ['action' => 'register', '--url' => 'https://example.test/hooks']);

        $requests = fakeVippsTransport()->requests;

        expect($requests)->toHaveCount(1)
            ->and($requests[0]['method'])->toBe('POST')
            ->and($requests[0]['path'])->toBe('/webhooks/v1/webhooks')
            ->and($requests[0]['json'])->toBe([
                'url' => 'https://example.test/hooks',
                'events' => VippsWebhooksCommand::DEFAULT_EVENTS,
            ])
            ->and(Str::isUuid((string) $requests[0]['idempotencyKey']))->toBeTrue();
    });

    it('uses the given --events instead of the defaults when passed', function () {
        fakeVippsTransport()->queueResponse(new ApiResponse(201, ['id' => 'wh-1', 'secret' => 's']));

        Artisan::call('vipps:webhooks', [
            'action' => 'register',
            '--url' => 'https://example.test/hooks',
            '--events' => ['epayments.payment.captured.v1', 'epayments.payment.expired.v1'],
        ]);

        expect(fakeVippsTransport()->requests[0]['json'])->toBe([
            'url' => 'https://example.test/hooks',
            'events' => ['epayments.payment.captured.v1', 'epayments.payment.expired.v1'],
        ]);
    });

    it('falls back to the named vipps.webhooks route when --url is omitted', function () {
        Route::post('/vipps/webhooks', fn() => null)->name('vipps.webhooks');
        // Routes named after boot are invisible to Router::has() until the
        // name lookup table is rebuilt — a test-only quirk, since real apps
        // name routes during boot.
        Route::getRoutes()->refreshNameLookups();

        fakeVippsTransport()->queueResponse(new ApiResponse(201, ['id' => 'wh-1', 'secret' => 's']));

        $exit = Artisan::call('vipps:webhooks', ['action' => 'register']);

        expect($exit)->toBe(0)
            ->and(fakeVippsTransport()->requests[0]['json'])->toMatchArray([
                'url' => 'http://localhost/vipps/webhooks',
            ]);
    });

    it('fails with guidance when there is no --url and no vipps.webhooks route', function () {
        $this->artisan('vipps:webhooks', ['action' => 'register'])
            ->expectsOutputToContain('--url')
            ->expectsOutputToContain("Route::vippsWebhooks('/vipps/webhooks');")
            ->assertFailed();

        expect(fakeVippsTransport()->requests)->toBeEmpty();
    });

    it('never lets the secret reach the laravel log', function () {
        Log::spy();

        fakeVippsTransport()->queueResponse(new ApiResponse(201, [
            'id' => 'wh-123',
            'secret' => 'super-secret-value',
        ]));

        Artisan::call('vipps:webhooks', ['action' => 'register', '--url' => 'https://example.test/hooks']);

        // Sanity: the secret DID reach the terminal — otherwise "not logged"
        // would also pass for a command that lost the secret entirely.
        expect(Artisan::output())->toContain('super-secret-value');

        $secret = Mockery::on(
            static fn($arg): bool => str_contains((string) json_encode($arg), 'super-secret-value'),
        );
        $any = Mockery::any();

        // Cover both call shapes per PSR-3 level — (message) and
        // (message, context) — since the secret could hide in either slot.
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'write'] as $level) {
            Log::shouldNotHaveReceived($level, [$secret]);
            Log::shouldNotHaveReceived($level, [$secret, $any]);
            Log::shouldNotHaveReceived($level, [$any, $secret]);
        }

        // log($level, $message, $context) has the extra level argument.
        Log::shouldNotHaveReceived('log', [$any, $secret]);
        Log::shouldNotHaveReceived('log', [$any, $secret, $any]);
        Log::shouldNotHaveReceived('log', [$any, $any, $secret]);
    });
});

describe('list', function () {
    it('renders a table with id, url and events', function () {
        fakeVippsTransport()->queueResponse(new ApiResponse(200, [
            'webhooks' => [
                [
                    'id' => 'wh-1',
                    'url' => 'https://example.test/hooks',
                    'events' => ['recurring.charge-captured.v1', 'recurring.charge-failed.v1'],
                ],
                [
                    'id' => 'wh-2',
                    'url' => 'https://other.example.test/hooks',
                    'events' => ['epayments.payment.captured.v1'],
                ],
            ],
        ]));

        $exit = Artisan::call('vipps:webhooks', ['action' => 'list']);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('wh-1')
            ->toContain('https://example.test/hooks')
            ->toContain('recurring.charge-captured.v1')
            ->toContain('recurring.charge-failed.v1')
            ->toContain('wh-2')
            ->toContain('epayments.payment.captured.v1');

        expect(fakeVippsTransport()->requests[0])->toMatchArray([
            'method' => 'GET',
            'path' => '/webhooks/v1/webhooks',
        ]);
    });

    it('says so when nothing is registered', function () {
        fakeVippsTransport()->queueResponse(new ApiResponse(200, ['webhooks' => []]));

        Artisan::call('vipps:webhooks', ['action' => 'list']);

        expect(Artisan::output())->toContain('No webhooks are registered');
    });
});

describe('delete', function () {
    it('fails without --id and makes no API call', function () {
        $this->artisan('vipps:webhooks', ['action' => 'delete'])
            ->expectsOutputToContain('--id')
            ->assertFailed();

        expect(fakeVippsTransport()->requests)->toBeEmpty();
    });

    it('confirms and then deletes with a UUID idempotency key', function () {
        $this->artisan('vipps:webhooks', ['action' => 'delete', '--id' => 'wh-9'])
            ->expectsConfirmation(
                'Delete webhook [wh-9]? Deliveries stop immediately and its signing secret becomes useless.',
                'yes',
            )
            ->expectsOutputToContain('Webhook [wh-9] deleted.')
            ->assertSuccessful();

        $requests = fakeVippsTransport()->requests;

        expect($requests)->toHaveCount(1)
            ->and($requests[0]['method'])->toBe('DELETE')
            ->and($requests[0]['path'])->toBe('/webhooks/v1/webhooks/wh-9')
            ->and(Str::isUuid((string) $requests[0]['idempotencyKey']))->toBeTrue();
    });

    it('makes no API call and exits non-zero when the confirmation is declined', function () {
        // Exit code 1, not 0: a non-interactive run auto-declines the
        // confirmation, and success would tell a script the webhook was
        // deleted when nothing happened.
        $this->artisan('vipps:webhooks', ['action' => 'delete', '--id' => 'wh-9'])
            ->expectsConfirmation(
                'Delete webhook [wh-9]? Deliveries stop immediately and its signing secret becomes useless.',
                'no',
            )
            ->expectsOutputToContain('Aborted — nothing deleted.')
            ->assertExitCode(1);

        expect(fakeVippsTransport()->requests)->toBeEmpty();
    });

    it('skips the confirmation entirely with --force and deletes', function () {
        // No expectsConfirmation(): if the command still asked, the pending
        // PendingCommand would fail on the unexpected question. --force is
        // the scripted path, so it must never prompt.
        $this->artisan('vipps:webhooks', ['action' => 'delete', '--id' => 'wh-9', '--force' => true])
            ->expectsOutputToContain('Webhook [wh-9] deleted.')
            ->assertSuccessful();

        $requests = fakeVippsTransport()->requests;

        expect($requests)->toHaveCount(1)
            ->and($requests[0]['method'])->toBe('DELETE')
            ->and($requests[0]['path'])->toBe('/webhooks/v1/webhooks/wh-9');
    });
});

describe('unknown action', function () {
    it('errors and exits non-zero', function () {
        $this->artisan('vipps:webhooks', ['action' => 'promote'])
            ->expectsOutputToContain('Unknown action [promote]. Valid actions: register, list, delete.')
            ->assertFailed();

        expect(fakeVippsTransport()->requests)->toBeEmpty();
    });
});
