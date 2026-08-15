<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\SocialiteManager;
use LogicException;
use Nesthus\Vipps\Auth\Psr16TokenCache;
use Nesthus\Vipps\Environment;
use Nesthus\Vipps\Laravel\Console\VippsWebhooksCommand;
use Nesthus\Vipps\Laravel\Http\Controllers\VippsWebhookController;
use Nesthus\Vipps\Laravel\Http\Middleware\VerifyVippsWebhookSignature;
use Nesthus\Vipps\Laravel\Socialite\VippsSocialiteProvider;
use Nesthus\Vipps\SystemInfo;
use Nesthus\Vipps\Vipps;
use Nesthus\Vipps\VippsConfig;

/**
 * Wires nesthus/vipps-php into a Laravel app: one Vipps instance in the
 * container, built from config/vipps.php, with a Guzzle transport whose
 * timeouts are MANDATORY (non-positive values are refused at resolve time —
 * a payment call with no deadline can wedge a queue worker), and access
 * tokens cached in a Laravel cache store so workers share one token.
 *
 * Bindings are lazy: an app with no Vipps credentials boots fine and only
 * fails (loudly, with the missing field named) when something actually
 * resolves Vipps. Use Vipps::isConfigured()-style checks via
 * config('vipps.client_id') !== '' before resolving in optional code paths.
 */
final class VippsServiceProvider extends ServiceProvider
{
    public const VERSION = '0.1.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/vipps.php', 'vipps');

        $this->app->singleton(VippsConfig::class, function (Application $app): VippsConfig {
            /** @var array<string, mixed> $config */
            $config = $app->make('config')->get('vipps');

            /** @var array<string, mixed> $system */
            $system = is_array($config['system'] ?? null) ? $config['system'] : [];

            return new VippsConfig(
                clientId: self::stringValue($config, 'client_id'),
                clientSecret: self::stringValue($config, 'client_secret'),
                subscriptionKey: self::stringValue($config, 'subscription_key'),
                merchantSerialNumber: self::stringValue($config, 'merchant_serial_number'),
                environment: Environment::from(self::stringValue($config, 'environment') ?: 'test'),
                system: new SystemInfo(
                    systemName: self::stringValue($system, 'name') ?: (string) $app->make('config')->get('app.name', 'laravel'),
                    systemVersion: self::stringValue($system, 'version') ?: $app->version(),
                    pluginName: 'nesthus/vipps-laravel',
                    pluginVersion: self::VERSION,
                ),
            );
        });

        $this->app->singleton(Vipps::class, function (Application $app): Vipps {
            $timeout = (int) $app->make('config')->get('vipps.timeout');
            $connectTimeout = (int) $app->make('config')->get('vipps.connect_timeout');

            if ($timeout <= 0 || $connectTimeout <= 0) {
                throw new LogicException(
                    'vipps.timeout and vipps.connect_timeout must be positive integers — '
                    . 'Guzzle waits forever by default, and a payment call with no deadline can wedge a worker.',
                );
            }

            $psr17 = new HttpFactory();

            /** @var CacheManager $cacheManager */
            $cacheManager = $app->make('cache');
            /** @var string|null $store */
            $store = $app->make('config')->get('vipps.token_cache_store');

            return new Vipps(
                config: $app->make(VippsConfig::class),
                httpClient: new GuzzleClient([
                    'timeout' => $timeout,
                    'connect_timeout' => $connectTimeout,
                ]),
                requestFactory: $psr17,
                streamFactory: $psr17,
                tokenCache: new Psr16TokenCache($cacheManager->store($store)),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/vipps.php' => $this->app->configPath('vipps.php'),
        ], 'vipps-config');

        if ($this->app->runningInConsole()) {
            $this->commands([VippsWebhooksCommand::class]);
        }

        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('vipps.webhook-signature', VerifyVippsWebhookSignature::class);

        // Every invalid probe costs a body read + SHA-256 before rejection, so
        // the endpoint must be rate limited BEFORE the signature check —
        // otherwise unauthenticated traffic gets free CPU and a log line per
        // request. Defined here (not hardcoded in the macro) so an app can
        // override the limiter by registering its own under the same name
        // before boot.
        if (RateLimiter::limiter('vipps-webhooks') === null) {
            RateLimiter::for('vipps-webhooks', fn(Request $request): Limit => Limit::perMinute(120)->by($request->ip() ?? 'unknown'));
        }

        // One line in the consumer's routes file registers a signed webhook
        // receiver that turns deliveries into Laravel events:
        //     Route::vippsWebhooks('/vipps/webhooks');
        // Put it in a stateless group (routes/api.php) — server-to-server
        // calls need no session and must not hit CSRF.
        Route::macro('vippsWebhooks', function (string $uri) {
            // Route::any, not Route::post: a POST-only route answers GET/HEAD
            // probes with 405 and OPTIONS with 200+Allow before middleware
            // runs, confirming the endpoint exists to scanners. any() sends
            // every method through the middleware, which 404s non-POST — the
            // same response an unregistered path gives.
            //
            // The facade proxies to the same router instance the macro is
            // bound to, and the group attribute stack is router state — so
            // this respects an enclosing Route::group() while keeping the
            // closure free of $this-rebinding that static analysis cannot see.
            return Route::any($uri, VippsWebhookController::class)
                ->middleware(['throttle:vipps-webhooks', VerifyVippsWebhookSignature::class])
                ->name('vipps.webhooks');
        });

        $this->registerSocialiteDriver();
    }

    /**
     * Registers the 'vipps' Socialite driver when Socialite is installed.
     * Guarded by interface existence so the package does not hard-crash an
     * app that removed Socialite despite it being in our require — belt and
     * braces around an optional login feature.
     *
     * callAfterResolving instead of an eager make(): Socialite's provider is
     * deferrable, and instantiating the manager on every request just to
     * register a driver nobody may use would defeat that. The hook runs the
     * moment (and only if) something actually resolves Socialite.
     */
    private function registerSocialiteDriver(): void
    {
        if (! interface_exists(SocialiteFactory::class)) {
            return;
        }

        $this->callAfterResolving(SocialiteFactory::class, function (SocialiteFactory $socialite): void {
            // extend() lives on SocialiteManager, not on the Factory contract —
            // narrowing here keeps the guard honest instead of assuming the
            // binding is always the concrete manager.
            if (! $socialite instanceof SocialiteManager) {
                return;
            }

            $socialite->extend('vipps', function (Application $app): VippsSocialiteProvider {
                $config = $app->make('config');

                return VippsSocialiteProvider::make(
                    request: $app->make('request'),
                    clientId: (string) $config->get('vipps.client_id'),
                    clientSecret: (string) $config->get('vipps.client_secret'),
                    redirectUrl: (string) $config->get('vipps.login.redirect'),
                    environment: Environment::from((string) ($config->get('vipps.environment') ?: 'test')),
                    scopes: (string) $config->get('vipps.login.scopes'),
                    subscriptionKey: (string) $config->get('vipps.subscription_key'),
                    merchantSerialNumber: (string) $config->get('vipps.merchant_serial_number'),
                );
            });
        });
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function stringValue(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
