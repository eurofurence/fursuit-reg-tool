<?php

use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Event;
use App\Models\Species;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function badgeFor(User $user, Event $event, string $name, array $overrides = []): Badge
{
    $fursuit = $user->fursuits()->create([
        'event_id' => $event->id,
        'species_id' => Species::firstOrCreate(['name' => 'Wolf'], ['name' => 'Wolf', 'checked' => false])->id,
        'name' => $name,
        'image' => 'fursuits/'.$name.'.jpg',
        'status' => 'approved',
        'published' => false,
        'catch_em_all' => false,
    ]);

    return $fursuit->badges()->create(array_merge([
        'status_fulfillment' => 'pending',
        'status_payment' => 'unpaid',
        'subtotal' => 252,
        'tax_rate' => 0.19,
        'tax' => 48,
        'total' => 300,
        'is_free_badge' => false,
        'dual_side_print' => false,
        'apply_late_fee' => false,
    ], $overrides));
}

beforeEach(function () {
    $this->event = Event::factory()->create([
        'order_starts_at' => now()->subDays(2),
        'order_ends_at' => now()->addDays(20),
        'ends_at' => now()->addDays(20),
    ]);
});

test('a user with no badges owes nothing', function () {
    expect(User::factory()->create()->amountDue())->toBe(0);
});

test('sums the totals of unpaid badges only', function () {
    $user = User::factory()->create();

    badgeFor($user, $this->event, 'UnpaidA');
    badgeFor($user, $this->event, 'UnpaidB', ['total' => 200, 'subtotal' => 168, 'tax' => 32]);
    badgeFor($user, $this->event, 'AlreadyPaid', ['status_payment' => Paid::$name, 'paid_at' => now()]);

    expect($user->amountDue())->toBe(500);
});

test('free badges do not add to the amount due', function () {
    $user = User::factory()->create();

    badgeFor($user, $this->event, 'Free', [
        'status_payment' => Paid::$name,
        'paid_at' => now(),
        'is_free_badge' => true,
        'total' => 0,
        'subtotal' => 0,
        'tax' => 0,
    ]);

    expect($user->amountDue())->toBe(0);
});

test('paying a badge clears the amount it contributed', function () {
    $user = User::factory()->create();
    $badge = badgeFor($user, $this->event, 'ToPay');

    expect($user->amountDue())->toBe(300);

    $badge->status_payment->transitionTo(Paid::class);

    expect($user->amountDue())->toBe(0);
});

test('soft deleted badges are excluded', function () {
    $user = User::factory()->create();
    $badge = badgeFor($user, $this->event, 'Deleted');

    expect($user->amountDue())->toBe(300);

    $badge->delete();

    expect($user->amountDue())->toBe(0);
});

test('another users badges are not counted', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    badgeFor($other, $this->event, 'SomeoneElse');

    expect($user->amountDue())->toBe(0);
});

test('spare copies count towards the amount due', function () {
    $user = User::factory()->create();
    $main = badgeFor($user, $this->event, 'Main');
    badgeFor($user, $this->event, 'Copy', [
        'extra_copy_of' => $main->id,
        'total' => 200,
        'subtotal' => 168,
        'tax' => 32,
    ]);

    expect($user->amountDue())->toBe(500);
});

// Parity with the wallet was verified against 5,283 prod user wallets before the writes were
// removed: 5,143 agreed exactly, and the 140 that drifted were the reason for the removal.
// Nothing writes to the wallet any more, so there is no live mirror left to assert against.
// See docs/wallet-removal-plan.md.
