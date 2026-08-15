<?php

declare(strict_types=1);

use Nesthus\Vipps\Laravel\Facades\Vipps as VippsFacade;
use Nesthus\Vipps\Vipps;
use Nesthus\Vipps\VippsConfig;

/*
 * The facade is only sugar over the container singleton — these tests pin
 * that it proxies to THE instance, not a facade-private copy (which would
 * split the token cache between facade users and injection users).
 */

it('resolves the facade root to the container singleton', function (): void {
    expect(VippsFacade::getFacadeRoot())->toBe(app(Vipps::class));
});

it('proxies config() to the same VippsConfig the container holds', function (): void {
    // Identity across all three access paths (facade, Vipps binding,
    // VippsConfig binding) proves there is exactly one config object.
    expect(VippsFacade::config())
        ->toBeInstanceOf(VippsConfig::class)
        ->toBe(app(VippsConfig::class))
        ->toBe(app(Vipps::class)->config());
});
