<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Events;

/**
 * Typed companion to {@see VippsWebhookReceived} for
 * `recurring.agreement-rejected.v1`: the customer declined the pending
 * agreement in the Vipps app, so it never activated and no charges can ever
 * be made against it. The payload's `agreementId` names the agreement.
 *
 * Same listener discipline as the generic event: queue, persist, dedupe.
 */
final readonly class AgreementRejected
{
    /**
     * @param array<array-key, mixed> $payload the decoded, signature-verified JSON body exactly as delivered
     */
    public function __construct(public array $payload) {}
}
