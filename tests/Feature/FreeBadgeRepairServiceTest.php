<?php

use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Species;
use App\Models\User;
use App\Services\FreeBadgeRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function chargedBadge(User $user, Event $event, string $name, array $overrides = []): Badge
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
        'subtotal' => 420,
        'tax_rate' => 0.19,
        'tax' => 80,
        'total' => 500,
        'is_free_badge' => false,
        'dual_side_print' => true,
        'apply_late_fee' => false,
    ], $overrides));
}

beforeEach(function () {
    $this->event = Event::factory()->create([
        'order_starts_at' => now()->subDays(2),
        'order_ends_at' => now()->addDays(20),
        'ends_at' => now()->addDays(20),
    ]);
    $this->admin = User::factory()->create(['is_admin' => true]);
});

test('converts a wrongly charged prepaid badge to free, credits the wallet and logs it', function () {
    $user = User::factory()->create();
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $this->event->id,
        'attendee_id' => 'TEST-'.$user->id,
        'prepaid_badges' => 1,
        'valid_registration' => true,
    ]);

    $badge = chargedBadge($user, $this->event, 'Charged');
    $user->forcePay($badge);
    expect($user->fresh()->balanceInt)->toBe(-500);

    $result = app(FreeBadgeRepairService::class)->repair($this->event, $this->admin);

    expect($result['success'])->toBeTrue();
    expect($result['fixed_badge_count'])->toBe(1);
    expect($result['fixed_user_count'])->toBe(1);
    expect($result['total_refunded_cents'])->toBe(500);

    $badge->refresh();
    expect($badge->is_free_badge)->toBeTrue();
    expect((int) $badge->total)->toBe(0);
    expect((int) $badge->subtotal)->toBe(0);
    expect((int) $badge->tax)->toBe(0);
    expect($badge->status_payment->equals(Paid::class))->toBeTrue();

    // Wallet credited back to zero
    expect($user->fresh()->balanceInt)->toBe(0);

    // transactions + activity_log records exist
    $this->assertDatabaseHas('transactions', [
        'payable_id' => $user->id,
        'type' => 'deposit',
    ]);
    $this->assertDatabaseHas('activity_log', [
        'subject_id' => $badge->id,
        'causer_id' => $this->admin->id,
        'description' => 'Corrected wrongly charged prepaid badge to free',
    ]);
});

test('does not convert spare copies and leaves them paid', function () {
    $user = User::factory()->create();
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $this->event->id,
        'attendee_id' => 'TEST-'.$user->id,
        'prepaid_badges' => 2,
        'valid_registration' => true,
    ]);

    $main = chargedBadge($user, $this->event, 'Main');
    $copy = chargedBadge($user, $this->event, 'Main', [
        'extra_copy' => true,
        'extra_copy_of' => $main->id,
    ]);

    $result = app(FreeBadgeRepairService::class)->repair($this->event, $this->admin);

    // Only the main badge is converted; the spare copy stays paid.
    expect($result['fixed_badge_count'])->toBe(1);

    expect($main->fresh()->is_free_badge)->toBeTrue();
    expect($copy->fresh()->is_free_badge)->toBeFalse();
    expect((int) $copy->fresh()->total)->toBe(500);
});

test('only converts up to the prepaid entitlement', function () {
    $user = User::factory()->create();
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $this->event->id,
        'attendee_id' => 'TEST-'.$user->id,
        'prepaid_badges' => 2,
        'valid_registration' => true,
    ]);

    // 3 paid main badges but only 2 prepaid → exactly 2 become free.
    chargedBadge($user, $this->event, 'A');
    chargedBadge($user, $this->event, 'B');
    chargedBadge($user, $this->event, 'C');

    $result = app(FreeBadgeRepairService::class)->repair($this->event, $this->admin);

    expect($result['fixed_badge_count'])->toBe(2);
    expect(Badge::where('is_free_badge', true)->count())->toBe(2);
    expect(Badge::where('is_free_badge', false)->count())->toBe(1);
});

test('preview reports affected badges without mutating them', function () {
    $user = User::factory()->create();
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $this->event->id,
        'attendee_id' => 'TEST-'.$user->id,
        'prepaid_badges' => 1,
        'valid_registration' => true,
    ]);

    $badge = chargedBadge($user, $this->event, 'Charged');

    $report = app(FreeBadgeRepairService::class)->preview($this->event);

    expect($report['affected_badge_count'])->toBe(1);
    expect($report['affected_user_count'])->toBe(1);
    expect($report['total_refund_cents'])->toBe(500);
    expect($report['rows'][0]['fursuit'])->toBe('Charged');
    expect($report['rows'][0]['should_be_free'])->toBe(1);

    // Unchanged by preview
    expect($badge->fresh()->is_free_badge)->toBeFalse();
});

test('is safe to run twice (no double refund)', function () {
    $user = User::factory()->create();
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $this->event->id,
        'attendee_id' => 'TEST-'.$user->id,
        'prepaid_badges' => 1,
        'valid_registration' => true,
    ]);

    $badge = chargedBadge($user, $this->event, 'Charged');
    $user->forcePay($badge);

    $service = app(FreeBadgeRepairService::class);
    $service->repair($this->event, $this->admin);
    $secondRun = $service->repair($this->event, $this->admin);

    expect($secondRun['fixed_badge_count'])->toBe(0);
    expect($secondRun['total_refunded_cents'])->toBe(0);
    expect($user->fresh()->balanceInt)->toBe(0);
});

test('repair with no active event returns a failure result', function () {
    $result = app(FreeBadgeRepairService::class)->repair(null, $this->admin);

    expect($result['success'])->toBeFalse();
    expect($result['fixed_badge_count'])->toBe(0);
});
