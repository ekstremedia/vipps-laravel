<?php

declare(strict_types=1);

use Nesthus\Vipps\Environment;
use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Vipps;
use Nesthus\Vipps\VippsConfig;

/*
 * Container wiring for the package's two bindings. Config overrides are set
 * in each test BEFORE the first make() — both bindings are lazy singletons,
 * so a test can shape the world and then observe how resolution reacts.
 */

it('resolves Vipps as a singleton', function (): void {
    $first = app(Vipps::class);
    $second = app(Vipps::class);

    // Identity, not equality: a per-resolve instance would silently defeat
    // the shared token cache and duplicate Guzzle clients per call site.
    expect($second)->toBe($first);
});

it('builds the Vipps instance from the configured credentials', function (): void {
    $config = app(Vipps::class)->config();

    expect($config)->toBeInstanceOf(VippsConfig::class)
        ->and($config->clientId)->toBe('test-client-id')
        ->and($config->environment)->toBe(Environment::Test)
        ->and($config->system->pluginName)->toBe('nesthus/vipps-laravel');
});

it('shares one VippsConfig between the container binding and the Vipps instance', function (): void {
    // The Vipps singleton must be built FROM the VippsConfig binding, not a
    // parallel copy — otherwise a test (or app) swapping the binding would
    // observe two diverging configs.
    expect(app(Vipps::class)->config())->toBe(app(VippsConfig::class));
});

it('refuses to resolve Vipps when vipps.timeout is not positive', function (): void {
    config()->set('vipps.timeout', 0);

    app(Vipps::class);
})->throws(LogicException::class, 'deadline');

it('refuses to resolve Vipps when vipps.connect_timeout is not positive', function (): void {
    config()->set('vipps.connect_timeout', 0);

    app(Vipps::class);
})->throws(LogicException::class, 'deadline');

it('fails loudly with the missing field named when client_id is empty', function (): void {
    // Lazy loud failure: an unconfigured app boots fine, and only the code
    // path that actually resolves Vipps gets this exception — which must
    // name the field, or the operator greps logs for nothing.
    config()->set('vipps.client_id', '');

    app(VippsConfig::class);
})->throws(VippsConfigException::class, 'clientId');

it('maps environment production to Environment::Production', function (): void {
    config()->set('vipps.environment', 'production');

    expect(app(VippsConfig::class)->environment)->toBe(Environment::Production);
});

it('falls back to app.name and the framework version when system keys are null', function (): void {
    // config/vipps.php ships these as env()-backed nulls; the provider must
    // resolve the fallbacks at runtime (per app), not bake them into config.
    expect(config('vipps.system.name'))->toBeNull()
        ->and(config('vipps.system.version'))->toBeNull();

    $system = app(VippsConfig::class)->system;

    expect($system->systemName)->toBe((string) config('app.name'))
        ->and($system->systemName)->not->toBe('')
        ->and($system->systemVersion)->toBe(app()->version());
});

it('prefers an explicitly configured system name and version over the fallbacks', function (): void {
    config()->set('vipps.system.name', 'My Explicit Shop');
    config()->set('vipps.system.version', '9.9.9');

    $system = app(VippsConfig::class)->system;

    expect($system->systemName)->toBe('My Explicit Shop')
        ->and($system->systemVersion)->toBe('9.9.9');
});
