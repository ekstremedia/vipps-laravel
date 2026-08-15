<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Events;

/**
 * Typed companion to {@see VippsWebhookReceived} for
 * `recurring.charge-canceled.v1`: a pending or reserved charge was cancelled
 * before any money moved, so there is nothing to refund. The payload's
 * `chargeId` and `agreementId` name the pair.
 *
 * Same listener discipline as the generic event: queue, persist, dedupe.
 */
final readonly class ChargeCanceled
{
    /**
     * @param array<array-key, mixed> $payload the decoded, signature-verified JSON body exactly as delivered
     */
    public function __construct(public array $payload) {}
}
