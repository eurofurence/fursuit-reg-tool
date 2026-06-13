<?php

use App\Filament\Pages\DbService;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Species;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->event = Event::factory()->create([
        'order_starts_at' => now()->subDays(2),
        'order_ends_at' => now()->addDays(20),
        'ends_at' => now()->addDays(20),
    ]);
});

function makePaidBadge(User $user, Event $event): Badge
{
    $fursuit = $user->fursuits()->create([
        'event_id' => $event->id,
        'species_id' => Species::firstOrCreate(['name' => 'Wolf'], ['name' => 'Wolf', 'checked' => false])->id,
        'name' => 'Charged',
        'image' => 'fursuits/charged.jpg',
        'status' => 'approved',
        'published' => false,
        'catch_em_all' => false,
    ]);

    return $fursuit->badges()->create([
        'status_fulfillment' => 'pending',
        'status_payment' => 'unpaid',
        'subtotal' => 420,
        'tax_rate' => 0.19,
        'tax' => 80,
        'total' => 500,
        'is_free_badge' => false,
        'dual_side_print' => true,
        'apply_late_fee' => false,
    ]);
}

test('admin can access the DB Service maintenance page', function () {
    actingAs(User::factory()->create(['is_admin' => true]));

    get(route('filament.admin.pages.db-service'))->assertSuccessful();
});

test('non-admin (reviewer) cannot access the DB Service maintenance page', function () {
    actingAs(User::factory()->create(['is_admin' => false, 'is_reviewer' => true]));

    get(route('filament.admin.pages.db-service'))->assertForbidden();
});

test('page is hidden from navigation for non-admins', function () {
    actingAs(User::factory()->create(['is_admin' => false, 'is_reviewer' => true]));
    expect(DbService::shouldRegisterNavigation())->toBeFalse();

    actingAs(User::factory()->create(['is_admin' => true]));
    expect(DbService::shouldRegisterNavigation())->toBeTrue();
});

test('preview then apply fixes wrongly charged badges through the page', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $user = User::factory()->create();
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $this->event->id,
        'attendee_id' => 'TEST-'.$user->id,
        'prepaid_badges' => 1,
        'valid_registration' => true,
    ]);
    $badge = makePaidBadge($user, $this->event);

    actingAs($admin);

    Livewire::test(DbService::class)
        ->call('previewFreeBadgeFix')
        ->assertSet('reviewingFreeBadges', true)
        ->assertSet('freeBadgeReport.affected_badge_count', 1)
        ->call('applyFreeBadgeFix')
        ->assertSet('freeBadgeResult.success', true)
        ->assertSet('freeBadgeResult.fixed_badge_count', 1);

    expect($badge->fresh()->is_free_badge)->toBeTrue();
    expect((int) $badge->fresh()->total)->toBe(0);
});
