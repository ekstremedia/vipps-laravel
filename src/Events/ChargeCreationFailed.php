<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Events;

/**
 * Typed companion to {@see VippsWebhookReceived} for
 * `recurring.charge-creation-failed.v1`: a charge could not be created
 * against the agreement, so no charge exists to collect and nothing is
 * retried automatically — deciding whether to create a replacement charge is
 * the merchant's call. The payload's `agreementId` names the agreement.
 *
 * Same listener discipline as the generic event: queue, persist, dedupe.
 */
final readonly class ChargeCreationFailed
{
    /**
     * @param array<array-key, mixed> $payload the decoded, signature-verified JSON body exactly as delivered
     */
    public function __construct(public array $payload) {}
}
