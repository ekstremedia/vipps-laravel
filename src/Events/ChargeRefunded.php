<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Events;

/**
 * Typed companion to {@see VippsWebhookReceived} for
 * `recurring.charge-refunded.v1`: money from a captured charge was sent back
 * to the customer. Check the payload's amount fields before assuming the
 * whole charge was refunded — partial refunds land here too. The payload's
 * `chargeId` and `agreementId` name the pair.
 *
 * Same listener discipline as the generic event: queue, persist, dedupe.
 */
final readonly class ChargeRefunded
{
    /**
     * @param array<array-key, mixed> $payload the decoded, signature-verified JSON body exactly as delivered
     */
    public function __construct(public array $payload) {}
}
