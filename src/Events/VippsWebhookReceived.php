<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Events;

/**
 * Fired for EVERY signature-verified Vipps webhook delivery, whatever its
 * type — including types this package has never heard of, because Vipps adds
 * new event types without notice and an unknown type must degrade to "the
 * generic event fired" rather than to a dropped delivery.
 *
 * When the type is one this package recognises, a typed companion event (see
 * {@see EventMap}) is dispatched as well, so listeners can subscribe at
 * whichever granularity fits.
 *
 * Listener discipline (the controller answers 204 immediately, so all of
 * this is on you):
 *
 *   - QUEUE your listeners. Vipps expects an answer within 10 seconds and
 *     retries on timeout, so anything slow in a synchronous listener turns
 *     one delivery into many.
 *   - PERSIST AND DEDUPE before acting. Vipps retries with exponential
 *     backoff for days, and every retry lands here again — store the
 *     payload's event id and skip deliveries you have already processed.
 */
final readonly class VippsWebhookReceived
{
    /**
     * @param array<array-key, mixed> $payload the decoded JSON body exactly as delivered (empty when the body was not a JSON object/array — tolerated, never fatal)
     * @param string|null $eventType the payload's `eventType` field, or null when absent — null does NOT mean invalid, only untyped
     */
    public function __construct(
        public array $payload,
        public ?string $eventType,
    ) {}
}
