<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Tests;

use Nesthus\Vipps\Laravel\VippsServiceProvider;
use Orchestra\Testbench\TestCase as TestbenchTestCase;

/**
 * Testbench base: boots the package provider with a fully "configured" fake
 * sales unit, so tests exercise real container wiring. Tests that need the
 * UNCONFIGURED state override the config keys back to '' themselves.
 */
abstract class TestCase extends TestbenchTestCase
{
    protected function getPackageProviders($app): array
    {
        return [VippsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('vipps.client_id', 'test-client-id');
        $app['config']->set('vipps.client_secret', 'test-client-secret');
        $app['config']->set('vipps.subscription_key', 'test-subscription-key');
        $app['config']->set('vipps.merchant_serial_number', '123456');
        $app['config']->set('vipps.webhook_secret', 'test-webhook-secret');
        $app['config']->set('vipps.login.redirect', 'https://example.test/auth/vipps/callback');
    }
}
