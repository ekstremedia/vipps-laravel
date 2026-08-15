<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Nesthus\Vipps\Laravel\VippsServiceProvider;

/*
 * config/vipps.php must merge into an app that never published it — these
 * defaults are the contract a zero-config install runs on. The credential
 * keys are overridden by the test TestCase, so only the untouched ones are
 * asserted here.
 */

it('merges config defaults for environment and timeouts', function (): void {
    expect(config('vipps.environment'))->toBe('test')
        ->and(config('vipps.timeout'))->toBe(15)
        ->and(config('vipps.connect_timeout'))->toBe(5);
});

it('merges a default space-separated OIDC scope string', function (): void {
    expect(config('vipps.login.scopes'))->toBe('openid name email phoneNumber');
});

it('registers the vipps-config publishing tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(VippsServiceProvider::class, 'vipps-config');

    expect($paths)->not->toBeEmpty();

    // The tag must point a real shipped file at the app's config path —
    // a typo'd source path publishes nothing, silently.
    $source = (string) array_key_first($paths);

    expect(file_exists($source))->toBeTrue()
        ->and($paths[$source])->toEndWith('vipps.php');
});
