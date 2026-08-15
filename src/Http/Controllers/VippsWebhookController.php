<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nesthus\Vipps\Laravel\Events\EventMap;
use Nesthus\Vipps\Laravel\Events\VippsWebhookReceived;

/**
 * The receiver behind `Route::vippsWebhooks(...)`. By the time this runs,
 * {@see \Nesthus\Vipps\Laravel\Http\Middleware\VerifyVippsWebhookSignature}
 * has proven the delivery came from Vipps, so the only job left is to turn
 * it into Laravel events and answer fast:
 *
 *   1. {@see VippsWebhookReceived} fires for EVERY delivery — even unknown
 *      types, because Vipps adds event types without notice and new types
 *      must degrade to "generic event fired", never to a dropped delivery.
 *   2. A typed event ({@see EventMap}) fires as well when the type is one
 *      this package recognises.
 *
 * Vipps expects an answer within 10 seconds and treats a timeout as a
 * failure to retry, so this controller does nothing slow — no persistence,
 * no dedupe, no business logic. That work belongs to YOUR listeners, and
 * because deliveries are retried with backoff for days:
 *
 *   - QUEUE your listeners (implement ShouldQueue) so the 204 goes out
 *     immediately regardless of what the event triggers.
 *   - PERSIST the delivery and DEDUPE on the payload's event id before
 *     acting — every retry of the same event lands here again and fires
 *     the same events again.
 *
 * Deliberately tolerant of any body shape: a signature-verified delivery is
 * authentic by definition, so an unexpected shape still fires the generic
 * event (with an empty payload if the body wasn't JSON) rather than erroring
 * into Vipps' retry loop forever.
 */
final class VippsWebhookController
{
    public function __construct(private readonly Dispatcher $events) {}

    public function __invoke(Request $request): Response
    {
        $decoded = json_decode($request->getContent(), true);
        $payload = is_array($decoded) ? $decoded : [];

        $eventType = $payload['eventType'] ?? null;
        $eventType = is_string($eventType) && $eventType !== '' ? $eventType : null;

        // Generic first: a listener chain that throws on the typed event
        // must not prevent the catch-all from having fired.
        $this->events->dispatch(new VippsWebhookReceived($payload, $eventType));

        $typed = EventMap::eventFor($eventType, $payload);
        if ($typed !== null) {
            $this->events->dispatch($typed);
        }

        return response()->noContent();
    }
}
