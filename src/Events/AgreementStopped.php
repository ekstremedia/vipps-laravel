<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Events;

/**
 * Typed companion to {@see VippsWebhookReceived} for
 * `recurring.agreement-stopped.v1`: the agreement was stopped (by the
 * merchant or by the customer in the Vipps app) and no further charges can
 * be made against it. The payload's `agreementId` names the agreement —
 * check `actor` in the payload before assuming which side stopped it.
 *
 * Same listener discipline as the generic event: queue, persist, dedupe.
 */
final readonly class AgreementStopped
{
    /**
     * @param array<array-key, mixed> $payload the decoded, signature-verified JSON body exactly as delivered
     */
    public function __construct(public array $payload) {}
}
