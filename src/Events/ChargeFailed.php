<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Events;

/**
 * Typed companion to {@see VippsWebhookReceived} for
 * `recurring.charge-failed.v1`: the charge could not be completed (expired
 * before capture, or the payment source failed). Vipps does NOT retry a
 * failed charge — creating a replacement charge, dunning the customer, or
 * downgrading the subscription is the merchant's call to make here. The
 * payload's `chargeId` and `agreementId` name the pair.
 *
 * Same listener discipline as the generic event: queue, persist, dedupe.
 */
final readonly class ChargeFailed
{
    /**
     * @param array<array-key, mixed> $payload the decoded, signature-verified JSON body exactly as delivered
     */
    public function __construct(public array $payload) {}
}
