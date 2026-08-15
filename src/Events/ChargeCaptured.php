<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Events;

/**
 * Typed companion to {@see VippsWebhookReceived} for
 * `recurring.charge-captured.v1`: the charge was captured and the money is on
 * its way to the merchant — this is the event that usually extends the
 * customer's subscription. The payload's `chargeId` and `agreementId` name
 * the pair.
 *
 * Same listener discipline as the generic event: queue, persist, dedupe.
 */
final readonly class ChargeCaptured
{
    /**
     * @param array<array-key, mixed> $payload the decoded, signature-verified JSON body exactly as delivered
     */
    public function __construct(public array $payload) {}
}
