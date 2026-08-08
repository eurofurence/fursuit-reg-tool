<?php

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create([
        'starts_at' => Carbon::parse('2024-06-01'),
        'ends_at' => Carbon::parse('2024-06-30'),
        'order_starts_at' => Carbon::parse('2024-06-01'),
        'order_ends_at' => Carbon::parse('2024-06-25'),
    ]);

    EventUser::create([
        'user_id' => $this->user->id,
        'event_id' => $this->event->id,
        'attendee_id' => '12345',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    Storage::fake('local');
    Http::fake();
    Notification::fake();

    travelTo(Carbon::parse('2024-06-02'));
    actingAs($this->user);
});

function storedImageSize(string $path): array
{
    $info = getimagesizefromstring(Storage::get($path));

    return [$info[0], $info[1]];
}

function orderBadgeWith(array $overrides): TestResponse
{
    return post(route('badges.store'), array_merge([
        'species' => 'Wolf',
        'name' => 'Test Badge',
        'image' => UploadedFile::fake()->image('test.jpg', 1200, 1600),
        'catchEmAll' => false,
        'publish' => false,
        'tos' => true,
        'upgrades' => ['spareCopy' => false],
    ], $overrides));
}

test('the uploaded image is cropped server side to the requested rectangle', function () {
    orderBadgeWith([
        'image' => UploadedFile::fake()->image('test.jpg', 1000, 1000),
        'crop' => ['x' => 100, 'y' => 50, 'width' => 600, 'height' => 800],
    ])->assertRedirect(route('badges.index'));

    $fursuit = Badge::first()->fursuit;

    expect(storedImageSize($fursuit->image))->toBe([600, 800]);
});

test('an oversized crop is scaled down but keeps the 3:4 ratio', function () {
    orderBadgeWith([
        'image' => UploadedFile::fake()->image('test.jpg', 3000, 4000),
        'crop' => ['x' => 0, 'y' => 0, 'width' => 3000, 'height' => 4000],
    ])->assertRedirect(route('badges.index'));

    $fursuit = Badge::first()->fursuit;

    expect(storedImageSize($fursuit->image))->toBe([1500, 2000]);
});

test('a crop running past the edge is clamped to the image', function () {
    orderBadgeWith([
        'image' => UploadedFile::fake()->image('test.jpg', 800, 1000),
        'crop' => ['x' => 400, 'y' => 400, 'width' => 600, 'height' => 800],
    ])->assertRedirect(route('badges.index'));

    $fursuit = Badge::first()->fursuit;

    // 400x600 is left inside the image; the largest 3:4 box in that is 400x533.
    expect(storedImageSize($fursuit->image))->toBe([400, 533]);
});

test('without a crop the image is centre cropped to 3:4', function () {
    orderBadgeWith([
        'image' => UploadedFile::fake()->image('test.jpg', 1000, 1000),
    ])->assertRedirect(route('badges.index'));

    $fursuit = Badge::first()->fursuit;

    expect(storedImageSize($fursuit->image))->toBe([750, 1000]);
});

test('a png upload keeps its lossless format', function () {
    orderBadgeWith([
        'image' => UploadedFile::fake()->image('test.png', 1000, 1000),
        'crop' => ['x' => 0, 'y' => 0, 'width' => 600, 'height' => 800],
    ])->assertRedirect(route('badges.index'));

    $fursuit = Badge::first()->fursuit;

    expect($fursuit->image)->toEndWith('.png');
    expect(getimagesizefromstring(Storage::get($fursuit->image))[2])->toBe(IMAGETYPE_PNG);
});

test('a jpeg upload stays a jpeg', function () {
    orderBadgeWith([
        'crop' => ['x' => 0, 'y' => 0, 'width' => 600, 'height' => 800],
    ])->assertRedirect(route('badges.index'));

    $fursuit = Badge::first()->fursuit;

    expect($fursuit->image)->toEndWith('.jpg');
    expect(getimagesizefromstring(Storage::get($fursuit->image))[2])->toBe(IMAGETYPE_JPEG);
});

test('a crop smaller than the printable minimum is rejected', function () {
    orderBadgeWith([
        'crop' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 133],
    ])->assertSessionHasErrors(['crop.width', 'crop.height']);

    expect(Badge::count())->toBe(0);
});
