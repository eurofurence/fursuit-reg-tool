<?php

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;

uses(RefreshDatabase::class);

function badgeDeletedAt(Badge $badge)
{
    return DB::table('badges')->where('id', $badge->id)->value('deleted_at');
}

function fursuitDeletedAt(Fursuit $fursuit)
{
    return DB::table('fursuits')->where('id', $fursuit->id)->value('deleted_at');
}

test('soft-deleting a fursuit cascades to its badges', function () {
    $fursuit = Fursuit::factory()->create();
    $b1 = Badge::factory()->for($fursuit)->create(['extra_copy_of' => null]);
    $b2 = Badge::factory()->for($fursuit)->create(['extra_copy_of' => null]);

    $fursuit->delete();

    expect(fursuitDeletedAt($fursuit))->not->toBeNull();
    expect(badgeDeletedAt($b1))->not->toBeNull();
    expect(badgeDeletedAt($b2))->not->toBeNull();
});

test('soft-deleting the last badge cascades to its fursuit', function () {
    $fursuit = Fursuit::factory()->create();
    $badge = Badge::factory()->for($fursuit)->create(['extra_copy_of' => null]);

    $badge->delete();

    expect(badgeDeletedAt($badge))->not->toBeNull();
    expect(fursuitDeletedAt($fursuit))->not->toBeNull();
});

test('deleting one badge keeps the fursuit while another active badge remains', function () {
    $fursuit = Fursuit::factory()->create();
    $b1 = Badge::factory()->for($fursuit)->create(['extra_copy_of' => null]);
    $b2 = Badge::factory()->for($fursuit)->create(['extra_copy_of' => null]);

    $b1->delete();

    expect(badgeDeletedAt($b1))->not->toBeNull();
    expect(fursuitDeletedAt($fursuit))->toBeNull();
    expect(badgeDeletedAt($b2))->toBeNull();
});

test('soft-deleting a main badge cascades to its spare copies and the empty fursuit', function () {
    $fursuit = Fursuit::factory()->create();
    $main = Badge::factory()->for($fursuit)->create(['extra_copy_of' => null]);
    $copy = Badge::factory()->for($fursuit)->create(['extra_copy_of' => $main->id, 'extra_copy' => true]);

    $main->delete();

    expect(badgeDeletedAt($main))->not->toBeNull();
    expect(badgeDeletedAt($copy))->not->toBeNull();
    expect(fursuitDeletedAt($fursuit))->not->toBeNull();
});

test('public badge deletion soft-deletes the badge and its now-empty fursuit', function () {
    $event = Event::factory()->create([
        'starts_at' => now()->subDays(5),
        'ends_at' => now()->addDays(20),
        'order_starts_at' => now()->subDays(5),
        'order_ends_at' => now()->addDays(20),
    ]);
    $user = User::factory()->create();
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => 'TEST-'.$user->id,
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    $fursuit = Fursuit::factory()->recycle($user)->recycle($event)->create(['status' => 'approved']);
    $badge = Badge::factory()->for($fursuit)->create([
        'status_fulfillment' => 'pending',
        'extra_copy_of' => null,
    ]);

    actingAs($user);
    delete(route('badges.destroy', $badge))->assertRedirect();

    expect(badgeDeletedAt($badge))->not->toBeNull();
    expect(fursuitDeletedAt($fursuit))->not->toBeNull();
});

test('force-deleting a badge removes the row and does not cascade to the fursuit', function () {
    $fursuit = Fursuit::factory()->create();
    $badge = Badge::factory()->for($fursuit)->create(['extra_copy_of' => null]);

    $badge->forceDelete();

    expect(Badge::withTrashed()->find($badge->id))->toBeNull();
    // Force delete deliberately removes the row; the soft-delete cascade does not run.
    expect(fursuitDeletedAt($fursuit))->toBeNull();
});

test('backfill migration soft-deletes existing orphans and leaves healthy rows alone', function () {
    // Orphan A: active fursuit whose only badge was soft-deleted without cascade (legacy state).
    $fursuitA = Fursuit::factory()->create();
    $badgeA = Badge::factory()->for($fursuitA)->create(['extra_copy_of' => null]);
    DB::table('badges')->where('id', $badgeA->id)->update(['deleted_at' => now()]);

    // Orphan B: trashed fursuit with a still-active badge (the other direction).
    $fursuitB = Fursuit::factory()->create();
    $badgeB = Badge::factory()->for($fursuitB)->create(['extra_copy_of' => null]);
    DB::table('fursuits')->where('id', $fursuitB->id)->update(['deleted_at' => now()]);

    // Healthy: active fursuit with an active badge — must be left untouched.
    $healthy = Fursuit::factory()->create();
    Badge::factory()->for($healthy)->create(['extra_copy_of' => null]);

    expect(fursuitDeletedAt($fursuitA))->toBeNull();
    expect(badgeDeletedAt($badgeB))->toBeNull();

    $migration = include database_path('migrations/2026_06_14_120000_soft_delete_orphaned_fursuits_and_badges.php');
    $migration->up();
    $migration->up(); // idempotent — running twice changes nothing further

    expect(fursuitDeletedAt($fursuitA))->not->toBeNull();
    expect(badgeDeletedAt($badgeB))->not->toBeNull();
    expect(fursuitDeletedAt($healthy))->toBeNull();
});
