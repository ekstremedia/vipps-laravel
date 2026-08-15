<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Events;

/**
 * The one place the `eventType` string → typed event class mapping lives.
 * The controller consults it; nothing else should duplicate this table.
 *
 * Deliberately NOT exhaustive: Vipps adds event types without notice, so an
 * unmapped type is normal operation, not an error — the caller falls back to
 * dispatching only {@see VippsWebhookReceived}. Adding a new typed event is
 * one row here plus the event class itself.
 */
final class EventMap
{
    /**
     * The ten Recurring-API webhook types, per
     * https://developer.vippsmobilepay.com/docs/APIs/webhooks-api/events/ —
     * in the catalogue's own order (agreement lifecycle, then charge
     * lifecycle). Kept identical, key for key, to
     * {@see \Nesthus\Vipps\Laravel\Console\VippsWebhooksCommand::DEFAULT_EVENTS};
     * a parity test fails the build if the two ever drift.
     */
    private const MAP = [
        'recurring.agreement-activated.v1' => AgreementActivated::class,
        'recurring.agreement-rejected.v1' => AgreementRejected::class,
        'recurring.agreement-stopped.v1' => AgreementStopped::class,
        'recurring.agreement-expired.v1' => AgreementExpired::class,
        'recurring.charge-reserved.v1' => ChargeReserved::class,
        'recurring.charge-captured.v1' => ChargeCaptured::class,
        'recurring.charge-canceled.v1' => ChargeCanceled::class,
        'recurring.charge-failed.v1' => ChargeFailed::class,
        'recurring.charge-creation-failed.v1' => ChargeCreationFailed::class,
        'recurring.charge-refunded.v1' => ChargeRefunded::class,
    ];

    /**
     * Static table only — never instantiated.
     */
    private function __construct() {}

    /**
     * Every `eventType` string this package maps to a typed event, in
     * catalogue order. Exists so tests (and apps) can compare against the
     * command's default registration set without duplicating the table.
     *
     * @return list<string>
     */
    public static function eventTypes(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Builds the typed event for a delivery, or null when the type is
     * unknown (or absent). Construction lives here rather than returning a
     * class-string so every typed event is guaranteed to be built the same
     * way — payload in, nothing else.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function eventFor(?string $eventType, array $payload): ?object
    {
        if ($eventType === null) {
            return null;
        }

        $class = self::MAP[$eventType] ?? null;

        return $class === null ? null : new $class($payload);
    }
}
