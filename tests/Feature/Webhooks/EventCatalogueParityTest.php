<?php

declare(strict_types=1);

// Namespaced (with the global fallback covering it()/expect()) so the fixture
// function below cannot collide with a same-named global in another test
// file — Pest loads every test file into one PHP process.

namespace Nesthus\Vipps\Laravel\Tests\Feature\Webhooks;

use Nesthus\Vipps\Laravel\Console\VippsWebhooksCommand;
use Nesthus\Vipps\Laravel\Events\EventMap;

/*
 * Parity guard for the recurring.* event catalogue. The same ten names live
 * in two places — EventMap (what deliveries dispatch) and the command's
 * DEFAULT_EVENTS (what `vipps:webhooks register` subscribes to) — and 0.1.0
 * shipped with a fictional 'recurring.charge-charged.v1' precisely because
 * nothing pinned either list to the documented catalogue. The list below is
 * written out longhand, from the official events page
 * (https://developer.vippsmobilepay.com/docs/APIs/webhooks-api/events/,
 * verified 2026-08-15), NEVER derived from the constants it checks —
 * deriving it would make every future drift self-approving.
 */

/**
 * @return list<string> the documented recurring.* webhook catalogue, in the
 *                      docs' own order: agreement lifecycle, then charge lifecycle
 */
function documentedRecurringCatalogue(): array
{
    return [
        'recurring.agreement-activated.v1',
        'recurring.agreement-rejected.v1',
        'recurring.agreement-stopped.v1',
        'recurring.agreement-expired.v1',
        'recurring.charge-reserved.v1',
        'recurring.charge-captured.v1',
        'recurring.charge-canceled.v1',
        'recurring.charge-failed.v1',
        'recurring.charge-creation-failed.v1',
        'recurring.charge-refunded.v1',
    ];
}

it('maps exactly the ten documented recurring event types', function (): void {
    expect(EventMap::eventTypes())->toBe(documentedRecurringCatalogue());
});

it('registers exactly the ten documented recurring event types by default', function (): void {
    expect(VippsWebhooksCommand::DEFAULT_EVENTS)->toBe(documentedRecurringCatalogue());
});

it('keeps the event map and the command defaults identical, so the two can never drift', function (): void {
    // Redundant with the two tests above ON PURPOSE: if the hardcoded list
    // is ever edited to match one side, this still fails until the other
    // side moves too.
    expect(VippsWebhooksCommand::DEFAULT_EVENTS)->toBe(EventMap::eventTypes());
});

it('builds a distinct typed event class for every catalogue type', function (): void {
    $payload = ['eventId' => 'evt-parity'];

    $classes = [];
    foreach (documentedRecurringCatalogue() as $eventType) {
        $event = EventMap::eventFor($eventType, $payload);

        expect($event)->not->toBeNull();
        $classes[] = $event::class;
    }

    // Ten types, ten different classes — a copy-paste row pointing two types
    // at the same event would pass everything above.
    expect(array_unique($classes))->toHaveCount(count(documentedRecurringCatalogue()));
});
