<?php

declare(strict_types=1);

// Namespaced (with the global fallback covering it()/expect()) so the fixture
// function below cannot collide with a same-named global in another test
// file — Pest loads every test file into one PHP process.

namespace Nesthus\Vipps\Laravel\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Nesthus\Vipps\Laravel\Events\AgreementActivated;
use Nesthus\Vipps\Laravel\Events\AgreementExpired;
use Nesthus\Vipps\Laravel\Events\AgreementRejected;
use Nesthus\Vipps\Laravel\Events\AgreementStopped;
use Nesthus\Vipps\Laravel\Events\ChargeCanceled;
use Nesthus\Vipps\Laravel\Events\ChargeCaptured;
use Nesthus\Vipps\Laravel\Events\ChargeCreationFailed;
use Nesthus\Vipps\Laravel\Events\ChargeFailed;
use Nesthus\Vipps\Laravel\Events\ChargeRefunded;
use Nesthus\Vipps\Laravel\Events\ChargeReserved;
use Nesthus\Vipps\Laravel\Events\VippsWebhookReceived;
use Nesthus\Vipps\Laravel\Tests\Support\WebhookSigner;

/**
 * The happy-path half of the webhook receiver: a validly signed delivery
 * through the `Route::vippsWebhooks` macro answers 204 and turns into
 * events. Every request here is signed for real (WebhookSigner mirrors the
 * SDK's own test vectors), so these tests cover the middleware→controller
 * pipeline end to end — including the 'vipps-webhooks' throttle the macro
 * puts in front (its limiter is registered by the provider's boot; testbench
 * hit-counts it in the default array cache store) — not a stubbed validator.
 */
beforeEach(function (): void {
    Route::vippsWebhooks('/hooks/vipps');
});

/**
 * Delivers $body with valid (or caller-overridden) signature headers, the
 * raw-body way: the signature covers exact bytes, so the JSON test helpers
 * (which re-encode arrays) can never be used here.
 *
 * @param array<string, string>|null $headers
 */
function postSignedWebhook(string $body, ?array $headers = null): TestResponse
{
    $headers ??= WebhookSigner::headers($body);

    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', '/hooks/vipps', [], [], [], $server, $body);
}

it('answers 204 and dispatches both the generic and the typed event for a valid signed delivery', function (): void {
    Event::fake();

    $payload = [
        'eventType' => 'recurring.charge-captured.v1',
        'eventId' => 'evt-42',
        'agreementId' => 'agr-123',
        'chargeId' => 'chr-456',
    ];

    postSignedWebhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertNoContent();

    Event::assertDispatched(
        VippsWebhookReceived::class,
        fn(VippsWebhookReceived $event): bool => $event->eventType === 'recurring.charge-captured.v1'
            && $event->payload === $payload,
    );
    Event::assertDispatched(
        ChargeCaptured::class,
        fn(ChargeCaptured $event): bool => $event->payload === $payload,
    );
});

it('dispatches the matching typed event for every mapped recurring type', function (string $eventType, string $eventClass): void {
    Event::fake();

    $payload = ['eventType' => $eventType, 'eventId' => 'evt-1'];

    postSignedWebhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertNoContent();

    Event::assertDispatched($eventClass, fn(object $event): bool => $event->payload === $payload);
    Event::assertDispatched(VippsWebhookReceived::class, fn(VippsWebhookReceived $event): bool => $event->eventType === $eventType);
})->with([
    'agreement activated' => ['recurring.agreement-activated.v1', AgreementActivated::class],
    'agreement rejected' => ['recurring.agreement-rejected.v1', AgreementRejected::class],
    'agreement stopped' => ['recurring.agreement-stopped.v1', AgreementStopped::class],
    'agreement expired' => ['recurring.agreement-expired.v1', AgreementExpired::class],
    'charge reserved' => ['recurring.charge-reserved.v1', ChargeReserved::class],
    'charge captured' => ['recurring.charge-captured.v1', ChargeCaptured::class],
    'charge canceled' => ['recurring.charge-canceled.v1', ChargeCanceled::class],
    'charge failed' => ['recurring.charge-failed.v1', ChargeFailed::class],
    'charge creation failed' => ['recurring.charge-creation-failed.v1', ChargeCreationFailed::class],
    'charge refunded' => ['recurring.charge-refunded.v1', ChargeRefunded::class],
]);

it('dispatches only the generic event for an unknown eventType', function (): void {
    // Vipps adds event types without notice — an unmapped type must degrade
    // to "generic event fired", never to an error into Vipps' retry loop.
    Event::fake();

    $payload = ['eventType' => 'epayments.payment.captured.v1', 'eventId' => 'evt-9'];

    postSignedWebhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertNoContent();

    Event::assertDispatched(
        VippsWebhookReceived::class,
        fn(VippsWebhookReceived $event): bool => $event->eventType === 'epayments.payment.captured.v1',
    );

    foreach ([
        AgreementActivated::class, AgreementRejected::class, AgreementStopped::class, AgreementExpired::class,
        ChargeReserved::class, ChargeCaptured::class, ChargeCanceled::class, ChargeFailed::class,
        ChargeCreationFailed::class, ChargeRefunded::class,
    ] as $typedEvent) {
        Event::assertNotDispatched($typedEvent);
    }
});

it('never dispatches the retired charge-charged type name', function (): void {
    // 'recurring.charge-charged.v1' was a back-formation from the SDK's
    // ChargeStatus::CHARGED — no such webhook type exists in the official
    // catalogue. A delivery carrying it (there will never be one, but the
    // name shipped in 0.1.0 docs) must degrade to the generic event only.
    Event::fake();

    $payload = ['eventType' => 'recurring.charge-charged.v1', 'eventId' => 'evt-8'];

    postSignedWebhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertNoContent();

    Event::assertDispatched(VippsWebhookReceived::class);
    Event::assertNotDispatched(ChargeCaptured::class);
});

it('resolves the event type to null when the payload has no eventType field', function (): void {
    Event::fake();

    $payload = ['eventId' => 'evt-7', 'agreementId' => 'agr-1'];

    postSignedWebhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertNoContent();

    Event::assertDispatched(
        VippsWebhookReceived::class,
        fn(VippsWebhookReceived $event): bool => $event->eventType === null && $event->payload === $payload,
    );
});

it('tolerates a signed non-JSON body by dispatching the generic event with an empty payload', function (): void {
    // A signature-verified delivery is authentic by definition, so an
    // unexpected body shape still gets a 204 — erroring would put Vipps
    // into a retry loop that can never succeed.
    Event::fake();

    postSignedWebhook('this is not json')->assertNoContent();

    Event::assertDispatched(
        VippsWebhookReceived::class,
        fn(VippsWebhookReceived $event): bool => $event->payload === [] && $event->eventType === null,
    );
});

it('answers 204 even when no listener exists', function (): void {
    // No Event::fake here: the real dispatcher runs with zero registered
    // listeners, which is exactly a fresh install's state.
    $body = json_encode(['eventType' => 'recurring.charge-captured.v1', 'eventId' => 'evt-3'], JSON_THROW_ON_ERROR);

    postSignedWebhook($body)->assertNoContent();
});
