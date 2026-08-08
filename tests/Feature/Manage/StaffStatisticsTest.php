<?php

/*
 * What one desk clerk did, reconstructed from the timestamps three tables already carry.
 *
 * The interesting assertions here are about the reconstruction, not the counting. There is
 * no shift log anywhere in this application - the POS records `staff.last_login_at` and
 * nothing else - so hours worked are derived by cutting a member's action timeline wherever
 * the gap exceeds the idle threshold. These tests pin that cut, because getting it wrong is
 * silent: a member who worked two hours across two days would simply read as having worked
 * for two days straight, and the number would look perfectly reasonable.
 */

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Active;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Domain\Printing\Models\PrintBatch;
use App\Enum\PrintBatchStatusEnum;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Staff;
use App\Models\User;
use App\Services\StaffStatisticsService;

/**
 * A handover by this member, at this instant, for this event.
 */
function staffStatsHandover(Staff $staff, Event $event, string $at): Badge
{
    return Badge::factory()->create([
        'fursuit_id' => Fursuit::factory()->create(['event_id' => $event->id]),
        'status_fulfillment' => PickedUp::$name,
        'picked_up_at' => $at,
        'picked_up_by_staff_id' => $staff->id,
    ]);
}

function staffStats(Staff $staff, ?Event $event = null): array
{
    return app(StaffStatisticsService::class)->for($staff, $event);
}

beforeEach(function () {
    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 30',
        'starts_at' => '2026-09-01',
        'ends_at' => '2026-09-05',
    ]);

    $this->staff = Staff::factory()->create(['name' => 'Desk Lead']);
});

test('a member with no history reports zeroes rather than nulls in the counts', function () {
    $stats = staffStats($this->staff, $this->event);

    expect($stats['handovers']['badges'])->toBe(0)
        ->and($stats['checkouts']['count'])->toBe(0)
        ->and($stats['printing']['runs'])->toBe(0)
        ->and($stats['time']['activeSeconds'])->toBe(0)
        ->and($stats['time']['shifts'])->toBe(0)
        ->and($stats['perDay'])->toBe([])
        ->and($stats['busiestHour'])->toBeNull()
        // No transactions happened, so there is no average to report. Zero would be a
        // claim about speed; null is the absence of one.
        ->and($stats['time']['avgTransactionSeconds'])->toBeNull()
        ->and($stats['time']['longestTransactionSeconds'])->toBeNull();
});

test('handovers are counted and attributed only to the member who made them', function () {
    $other = Staff::factory()->create();

    staffStatsHandover($this->staff, $this->event, '2026-09-02 10:00:00');
    staffStatsHandover($this->staff, $this->event, '2026-09-02 10:05:00');
    staffStatsHandover($other, $this->event, '2026-09-02 10:06:00');

    expect(staffStats($this->staff, $this->event)['handovers']['badges'])->toBe(2)
        ->and(staffStats($other, $this->event)['handovers']['badges'])->toBe(1);
});

test('a badge reverted to ready-for-pickup stops counting as a handover', function () {
    // The POS error correction leaves `picked_up_at` in place, so the staff id alone
    // would keep crediting a handover that was undone.
    $badge = staffStatsHandover($this->staff, $this->event, '2026-09-02 10:00:00');
    $badge->forceFill(['status_fulfillment' => ReadyForPickup::$name])->saveQuietly();

    expect(staffStats($this->staff, $this->event)['handovers']['badges'])->toBe(0);
});

test('a gap longer than the idle threshold ends a shift and is not counted as worked', function () {
    // Two clusters an hour apart. Within each, four minutes between handovers.
    staffStatsHandover($this->staff, $this->event, '2026-09-02 10:00:00');
    staffStatsHandover($this->staff, $this->event, '2026-09-02 10:04:00');
    staffStatsHandover($this->staff, $this->event, '2026-09-02 11:30:00');
    staffStatsHandover($this->staff, $this->event, '2026-09-02 11:34:00');

    $stats = staffStats($this->staff, $this->event);

    expect($stats['time']['shifts'])->toBe(2)
        // Four minutes of transactions in each shift, plus one lead-in per shift. The
        // 86 minutes between them are not worked time.
        ->and($stats['time']['transactionSeconds'])->toBe(480)
        ->and($stats['time']['activeSeconds'])->toBe(480 + 2 * StaffStatisticsService::LEAD_IN_SECONDS);
});

test('a gap inside the threshold is a slow transaction, not a break', function () {
    staffStatsHandover($this->staff, $this->event, '2026-09-02 10:00:00');
    staffStatsHandover($this->staff, $this->event, '2026-09-02 10:01:00');
    staffStatsHandover($this->staff, $this->event, '2026-09-02 10:16:00');

    $stats = staffStats($this->staff, $this->event);

    expect($stats['time']['shifts'])->toBe(1)
        ->and($stats['time']['longestTransactionSeconds'])->toBe(900)
        ->and($stats['time']['avgTransactionSeconds'])->toBe(480)
        ->and($stats['time']['medianTransactionSeconds'])->toBe(480);
});

test('a single action still reports a shift rather than zero hours worked', function () {
    staffStatsHandover($this->staff, $this->event, '2026-09-02 10:00:00');

    $stats = staffStats($this->staff, $this->event);

    expect($stats['time']['shifts'])->toBe(1)
        ->and($stats['time']['transactionSeconds'])->toBe(0)
        ->and($stats['time']['activeSeconds'])->toBe(StaffStatisticsService::LEAD_IN_SECONDS)
        // One minute on the clock is not enough for a per-hour figure to mean anything.
        ->and($stats['handovers']['perHour'])->toBeNull();
});

test('days are broken out separately, each with its own hours and rate', function () {
    foreach (['10:00:00', '10:30:00', '11:00:00'] as $at) {
        staffStatsHandover($this->staff, $this->event, "2026-09-02 $at");
    }

    staffStatsHandover($this->staff, $this->event, '2026-09-03 09:00:00');

    $perDay = staffStats($this->staff, $this->event)['perDay'];

    // Half-hour gaps exceed the threshold, so day one is three one-action shifts.
    expect(collect($perDay)->pluck('date')->all())->toBe(['2026-09-02', '2026-09-03'])
        ->and($perDay[0]['badges'])->toBe(3)
        ->and($perDay[0]['shifts'])->toBe(3)
        ->and($perDay[1]['badges'])->toBe(1);
});

test('checkouts count only when finished, and their money is reported in cents', function () {
    $user = User::factory()->create();

    Checkout::create([
        'status' => Finished::$name,
        'user_id' => $user->id,
        'cashier_id' => $this->staff->id,
        'subtotal' => 1000,
        'tax' => 190,
        'total' => 1190,
        'fiskaly_data' => [],
        'created_at' => '2026-09-02 10:00:00',
    ]);

    // A basket nobody paid. It never took money and must not be counted as a till.
    Checkout::create([
        'status' => Active::$name,
        'user_id' => $user->id,
        'cashier_id' => $this->staff->id,
        'subtotal' => 500,
        'tax' => 95,
        'total' => 595,
        'fiskaly_data' => [],
        'created_at' => '2026-09-02 10:05:00',
    ]);

    $stats = staffStats($this->staff, $this->event);

    expect($stats['checkouts']['count'])->toBe(1)
        ->and($stats['checkouts']['revenueCents'])->toBe(1190)
        // The unpaid basket is not on the timeline either.
        ->and($stats['time']['actions'])->toBe(1);
});

test('print runs are counted with the cards in them', function () {
    PrintBatch::create([
        'name' => 'Run 1',
        'event_id' => $this->event->id,
        'created_by_staff_id' => $this->staff->id,
        'status' => PrintBatchStatusEnum::Completed,
        'total_jobs' => 20,
        'printed_count' => 18,
        'created_at' => '2026-09-02 10:00:00',
    ]);

    $stats = staffStats($this->staff, $this->event);

    expect($stats['printing']['runs'])->toBe(1)
        ->and($stats['printing']['cards'])->toBe(20)
        ->and($stats['printing']['printedCards'])->toBe(18);
});

test('the event scopes the numbers, and all time gathers every event', function () {
    $other = Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => '2025-09-01',
        'ends_at' => '2025-09-05',
    ]);

    staffStatsHandover($this->staff, $this->event, '2026-09-02 10:00:00');
    staffStatsHandover($this->staff, $other, '2025-09-02 10:00:00');

    expect(staffStats($this->staff, $this->event)['handovers']['badges'])->toBe(1)
        ->and(staffStats($this->staff, $other)['handovers']['badges'])->toBe(1)
        ->and(staffStats($this->staff, null)['handovers']['badges'])->toBe(2);
});

test('the busiest hour is the clock hour holding the most actions', function () {
    staffStatsHandover($this->staff, $this->event, '2026-09-02 10:00:00');
    staffStatsHandover($this->staff, $this->event, '2026-09-02 14:00:00');
    staffStatsHandover($this->staff, $this->event, '2026-09-02 14:10:00');
    staffStatsHandover($this->staff, $this->event, '2026-09-02 14:20:00');

    expect(staffStats($this->staff, $this->event)['busiestHour'])
        ->toBe(['hour' => 14, 'actions' => 3]);
});
