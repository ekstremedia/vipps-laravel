<?php

declare(strict_types=1);

// Namespaced (with the global fallback covering it()/expect()/config()) so
// the fixture functions below cannot collide with same-named globals in
// another test file — Pest loads every test file into one PHP process.

namespace Nesthus\Vipps\Laravel\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Nesthus\Vipps\Laravel\Events\VippsWebhookReceived;
use Nesthus\Vipps\Laravel\Tests\Support\WebhookSigner;

/**
 * The rejection half of the webhook receiver: the signature middleware in
 * front of the controller. What matters here is not just the status codes
 * but the ORDER (non-POST 404s before the secret is read, unconfigured 404s
 * before any validation runs), that a rejected delivery never reaches the
 * controller, and that log output carries only the validator's reason slug —
 * never signing material.
 *
 * The macro registers the URI with Route::any behind the 'vipps-webhooks'
 * throttle (limiter registered by the provider's boot; testbench counts hits
 * in the default array cache store), so every request here passes the
 * throttle before reaching the middleware under test.
 */
beforeEach(function (): void {
    Route::vippsWebhooks('/hooks/vipps');
});

/**
 * Raw-body request: the signature covers exact bytes, so the JSON test
 * helpers (which re-encode arrays) can never be used for webhook deliveries.
 *
 * @param array<string, string> $headers
 */
function callRawWebhook(string $method, string $body, array $headers): TestResponse
{
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call($method, '/hooks/vipps', [], [], [], $server, $body);
}

/**
 * @param array<string, string> $headers
 */
function postRawWebhook(string $body, array $headers): TestResponse
{
    return callRawWebhook('POST', $body, $headers);
}

it('answers 404 to every non-POST method, even correctly signed and fully configured', function (string $method): void {
    // The macro's Route::any exists so this middleware sees every verb: a
    // POST-only route would answer 405 (with an Allow header) or 200 to an
    // OPTIONS probe, confirming the endpoint exists. Signed validly FOR the
    // probed method to prove the 404 is the method check running first —
    // reaching the validator would answer 401.
    Event::fake();

    $body = '{"eventType":"recurring.charge-captured.v1"}';

    callRawWebhook($method, $body, WebhookSigner::headers($body, method: $method))
        ->assertNotFound();

    Event::assertNotDispatched(VippsWebhookReceived::class);
})->with(['GET', 'HEAD', 'OPTIONS', 'PUT', 'PATCH', 'DELETE']);

it('answers 404 to a non-POST probe before the secret check — also when unconfigured', function (): void {
    // Same 404 whether or not a secret is configured: the two checks must be
    // indistinguishable from outside, or method probing could reveal which
    // installs have webhooks configured.
    config()->set('vipps.webhook_secret', '');

    callRawWebhook('GET', '', [])->assertNotFound();
});

it('answers 404 when no webhook secret is configured, even to a well-formed signed request', function (): void {
    config()->set('vipps.webhook_secret', '');

    $body = '{"eventType":"recurring.charge-captured.v1"}';

    // Signed perfectly (with *some* secret) to prove the 404 comes from the
    // config check running FIRST — a validation failure would answer 401,
    // which would confirm to a scanner that the endpoint exists.
    postRawWebhook($body, WebhookSigner::headers($body, secret: 'whatever-secret'))
        ->assertNotFound();
});

it('answers 404 to a bare unsigned probe when unconfigured', function (): void {
    config()->set('vipps.webhook_secret', '');

    postRawWebhook('{}', [])->assertNotFound();
});

it('answers 401 when the body was tampered with after signing', function (): void {
    Event::fake();

    $headers = WebhookSigner::headers('{"eventType":"recurring.charge-captured.v1","amount":100}');

    postRawWebhook('{"eventType":"recurring.charge-captured.v1","amount":99900}', $headers)
        ->assertUnauthorized();

    // Rejection happens in the middleware, so the controller never ran. The
    // generic event fires for EVERY delivery that reaches the controller,
    // so its absence proves the forged delivery never got that far.
    Event::assertNotDispatched(VippsWebhookReceived::class);
});

it('answers 401 when the signature headers are missing entirely', function (): void {
    Event::fake();

    postRawWebhook('{"eventType":"recurring.charge-captured.v1"}', [])->assertUnauthorized();

    Event::assertNotDispatched(VippsWebhookReceived::class);
});

it('answers 401 when the delivery was signed with the wrong secret', function (): void {
    $body = '{"eventType":"recurring.charge-captured.v1"}';

    postRawWebhook($body, WebhookSigner::headers($body, secret: 'not-the-configured-secret'))
        ->assertUnauthorized();
});

it('logs a warning carrying only the validator reason — never any signing material', function (): void {
    Log::spy();

    $secret = 'test-webhook-secret';
    $headers = WebhookSigner::headers('{"amount":100}', secret: $secret);

    postRawWebhook('{"amount":99900}', $headers)->assertUnauthorized();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($secret, $headers): bool {
            // Context is EXACTLY the reason slug — nothing else about the
            // request may ride along into logs.
            expect($context)->toBe(['reason' => 'content_hash_mismatch'])
                ->and($message)->not->toContain($secret)
                ->and($message)->not->toContain($headers['Authorization']);

            return true;
        });
});
