<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Events;

/**
 * Typed companion to {@see VippsWebhookReceived} for
 * `recurring.charge-reserved.v1`: the charge amount is reserved on the
 * customer's payment source but NOT yet transferred — capture it to collect
 * the money. The payload's `chargeId` and `agreementId` name the pair.
 *
 * Same listener discipline as the generic event: queue, persist, dedupe.
 */
final readonly class ChargeReserved
{
    /**
     * @param array<array-key, mixed> $payload the decoded, signature-verified JSON body exactly as delivered
     */
    public function __construct(public array $payload) {}
}
