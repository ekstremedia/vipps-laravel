<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Nesthus\Vipps\Laravel\Http\Controllers\VippsWebhookController;
use Nesthus\Vipps\Laravel\Http\Middleware\VerifyVippsWebhookSignature;

/*
 * These tests assert on the ROUTE DEFINITION only — class-string comparisons
 * via ::class literals, which PHP resolves at compile time without
 * autoloading. Deliberate: the controller and middleware behavior have their
 * own tests, and dispatching here would couple this wiring test to them.
 */

/*
 * Laravel checks method_exists($controller, '__invoke') when the route is
 * REGISTERED (RouteAction::makeInvokable), so the controller class must
 * exist even though nothing is dispatched here. When the real controller is
 * not autoloadable yet, define a no-op stand-in — via eval, never a file, so
 * an optimized composer classmap can never map the real name to a stub.
 * Once the real class ships, class_exists() finds it and this is dead code.
 */
if (! class_exists(VippsWebhookController::class)) {
    eval(
        'namespace Nesthus\Vipps\Laravel\Http\Controllers;'
        . ' final class VippsWebhookController { public function __invoke(): void {} }'
    );
}

/**
 * @return RoutingRoute the route registered under the given name
 */
function vippsRouteNamed(string $name): RoutingRoute
{
    // Iterate instead of getByName(): the macro applies the name AFTER the
    // route is added to the collection, so the name lookup table may not
    // have been refreshed yet.
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getName() === $name) {
            return $route;
        }
    }

    throw new RuntimeException("No route named [{$name}] was registered.");
}

it('registers a named any-method route pointing at the webhook controller', function (): void {
    Route::vippsWebhooks('/hooks/vipps');

    $route = vippsRouteNamed('vipps.webhooks');

    // Route::any, not Route::post, is load-bearing: a POST-only route
    // answers GET/HEAD with 405 (plus an Allow header) and OPTIONS with 200
    // BEFORE any middleware runs, confirming to method-probing scanners that
    // the endpoint exists. Every verb must instead reach the signature
    // middleware, which 404s non-POST.
    foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
        expect($route->methods())->toContain($method);
    }

    expect($route->uri())->toBe('hooks/vipps')
        // The action may be stored bare or as "Class@__invoke" depending on
        // the framework's parse path — accept both, reject anything else.
        ->and((string) $route->getAction('uses'))->toStartWith(VippsWebhookController::class);
});

it('attaches the throttle and signature middleware to the macro route, throttle first', function (): void {
    Route::vippsWebhooks('/hooks/vipps');

    $route = vippsRouteNamed('vipps.webhooks');

    // Exact stack AND order: the rate limiter must run before the signature
    // check, or every invalid probe gets a free body read + SHA-256 and a
    // log line — the throttle exists precisely to cap that work.
    expect($route->middleware())->toBe([
        'throttle:vipps-webhooks',
        VerifyVippsWebhookSignature::class,
    ]);
});

it('registers an overridable vipps-webhooks rate limiter', function (): void {
    // The macro's throttle middleware is inert (worse: a 500 on first hit)
    // without a limiter registered under its name. The provider registers a
    // default only when the app has not already claimed the name, so apps
    // can tune the limit without forking the macro.
    expect(Illuminate\Support\Facades\RateLimiter::limiter('vipps-webhooks'))->not->toBeNull();
});

it('registers the vipps.webhook-signature middleware alias on the router', function (): void {
    $aliases = app('router')->getMiddleware();

    expect($aliases)->toHaveKey('vipps.webhook-signature')
        ->and($aliases['vipps.webhook-signature'])->toBe(VerifyVippsWebhookSignature::class);
});
