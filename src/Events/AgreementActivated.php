<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Events;

/**
 * Typed companion to {@see VippsWebhookReceived} for
 * `recurring.agreement-activated.v1`: the customer approved the agreement in
 * the Vipps app and charges may now be created against it. The payload's
 * `agreementId` names the agreement.
 *
 * Same listener discipline as the generic event: queue, persist, dedupe.
 */
final readonly class AgreementActivated
{
    /**
     * @param array<array-key, mixed> $payload the decoded, signature-verified JSON body exactly as delivered
     */
    public function __construct(public array $payload) {}
}
