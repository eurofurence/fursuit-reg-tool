<?php

use App\Jobs\GenerateFursuitWebpJob;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function galleryFursuit(array $overrides = []): Fursuit
{
    $user = User::factory()->create();
    $event = Event::factory()->create(['catch_em_all_enabled' => true]);
    $species = Species::create(['name' => 'Wolf', 'checked' => true]);

    $source = UploadedFile::fake()->image('original.jpg', 120, 160);
    Storage::put('fursuits/original.jpg', file_get_contents($source->getRealPath()));

    return Fursuit::create(array_merge([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'species_id' => $species->id,
        'name' => 'Testsuit',
        'image' => 'fursuits/original.jpg',
        'status' => 'approved',
        'published' => true,
        'catch_em_all' => false,
    ], $overrides));
}

it('drops the stale webp and queues a re-render when the photo is replaced', function () {
    $fursuit = galleryFursuit();
    $fursuit->forceFill(['image_webp' => 'gallery/fursuits/original.webp'])->saveQuietly();
    Storage::put('gallery/fursuits/original.webp', 'old-webp');

    Queue::fake();

    $fursuit->update(['image' => 'fursuits/replacement.jpg']);

    expect($fursuit->fresh()->image_webp)->toBeNull()
        ->and(Storage::exists('gallery/fursuits/original.webp'))->toBeFalse();

    Queue::assertPushed(GenerateFursuitWebpJob::class,
        fn (GenerateFursuitWebpJob $job) => $job->fursuitId === $fursuit->id);
});

it('never renders a variant on read, and never serves the master in its place', function () {
    Bus::fake();
    $fursuit = galleryFursuit();

    // The master is print quality - well over a megabyte at full size - so an unrendered
    // fursuit has no gallery URL at all rather than a heavyweight one.
    expect($fursuit->image_webp_url)->toBeNull()
        ->and($fursuit->image_thumb_url)->toBeNull()
        ->and($fursuit->fresh()->image_webp)->toBeNull()
        ->and(Storage::exists('gallery/fursuits/original.webp'))->toBeFalse();
});

it('hides fursuits whose variants have not been rendered yet', function () {
    Bus::fake();
    $fursuit = galleryFursuit();

    get(route('gallery.event', $fursuit->event_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('fursuits', 0));

    // Rendering the variants is what puts it on the wall.
    Bus::assertDispatched(GenerateFursuitWebpJob::class);
    (new GenerateFursuitWebpJob($fursuit->id))->handle();

    get(route('gallery.event', $fursuit->event_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('fursuits', 1)
            ->where('fursuits.0.thumb', fn ($url) => str_contains($url, 'thumbs/original.webp'))
        );
});

it('renders the webp for the current photo when the job runs', function () {
    $fursuit = galleryFursuit();
    $fursuit->forceFill(['image_webp' => 'gallery/fursuits/gone.webp'])->saveQuietly();

    (new GenerateFursuitWebpJob($fursuit->id))->handle();

    expect($fursuit->fresh()->image_webp)->toBe('gallery/fursuits/original.webp')
        ->and(Storage::exists('gallery/fursuits/original.webp'))->toBeTrue();
});

it('shows a folder card per event on the gallery landing page', function () {
    $fursuit = galleryFursuit();

    get(route('gallery.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Gallery/GalleryFolders')
            ->where('folders.0.id', $fursuit->event_id)
            ->where('folders.0.fursuits', 1)
        );
});

it('redirects old query string links into the matching folder', function () {
    $fursuit = galleryFursuit();

    get(route('gallery.index', ['event' => $fursuit->event_id]))
        ->assertRedirect(route('gallery.event', $fursuit->event_id));
});

it('renders the grid for one event folder', function () {
    $fursuit = galleryFursuit();

    get(route('gallery.event', $fursuit->event_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Gallery/GalleryIndex')
            ->where('fursuits.0.name', 'Testsuit')
            ->where('selected_event.id', $fursuit->event_id)
        );
});

test('empty filter values are treated as no filter', function () {
    $fursuit = galleryFursuit();

    // The grid always sends every filter; ConvertEmptyStringsToNull turns the empty ones
    // into nulls, which used to reach a string parameter and 500 the page.
    foreach ([route('gallery.all'), route('gallery.event', $fursuit->event_id)] as $url) {
        get($url.'?query=&species=&sort=')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('fursuits.0.name', 'Testsuit'));
    }

    get(route('gallery.load-more').'?query=&species=&sort=&event=')
        ->assertOk()
        ->assertJsonPath('fursuits.0.name', 'Testsuit');
});

test('an array filter value does not break the grid', function () {
    galleryFursuit();

    get(route('gallery.all').'?query[]=Test')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('fursuits.0.name', 'Testsuit'));
});

test('the gallery webp is capped at full hd', function () {
    Storage::put('fursuits/large.jpg', UploadedFile::fake()->image('large.jpg', 1500, 2000)->get());
    $fursuit = galleryFursuit(['image' => 'fursuits/large.jpg']);

    (new GenerateFursuitWebpJob($fursuit->id))->handle();

    $info = getimagesizefromstring(Storage::get($fursuit->fresh()->image_webp));

    expect([$info[0], $info[1]])->toBe([1080, 1440]);
});

test('the grid thumbnail is capped at 500px', function () {
    Storage::put('fursuits/thumbed.jpg', UploadedFile::fake()->image('thumbed.jpg', 1500, 2000)->get());
    $fursuit = galleryFursuit(['image' => 'fursuits/thumbed.jpg']);

    (new GenerateFursuitWebpJob($fursuit->id))->handle();

    $fursuit = $fursuit->fresh();

    expect($fursuit->image_thumb)->toBe('gallery/fursuits/thumbs/thumbed.webp');

    $info = getimagesizefromstring(Storage::get($fursuit->image_thumb));

    expect([$info[0], $info[1]])->toBe([375, 500]);
});

test('a replaced photo drops both variants', function () {
    Storage::put('fursuits/first.jpg', UploadedFile::fake()->image('first.jpg', 900, 1200)->get());
    $fursuit = galleryFursuit(['image' => 'fursuits/first.jpg']);

    (new GenerateFursuitWebpJob($fursuit->id))->handle();

    $oldWebp = $fursuit->fresh()->image_webp;
    $oldThumb = $fursuit->fresh()->image_thumb;

    Storage::put('fursuits/second.jpg', UploadedFile::fake()->image('second.jpg', 900, 1200)->get());
    $fursuit->update(['image' => 'fursuits/second.jpg']);

    // The queue runs inline here, so both variants have already followed the photo.
    expect($fursuit->fresh()->image_webp)->toBe('gallery/fursuits/second.webp');
    expect($fursuit->fresh()->image_thumb)->toBe('gallery/fursuits/thumbs/second.webp');
    expect(Storage::exists($oldWebp))->toBeFalse();
    expect(Storage::exists($oldThumb))->toBeFalse();
});

test('an image already below the cap is not upscaled', function () {
    Storage::put('fursuits/small.jpg', UploadedFile::fake()->image('small.jpg', 600, 800)->get());
    $fursuit = galleryFursuit(['image' => 'fursuits/small.jpg']);

    (new GenerateFursuitWebpJob($fursuit->id))->handle();

    $info = getimagesizefromstring(Storage::get($fursuit->fresh()->image_webp));

    expect([$info[0], $info[1]])->toBe([600, 800]);
});

test('public variants are served as stable unsigned urls', function () {
    config(['gallery.public_variants' => true]);

    $fursuit = galleryFursuit();
    (new GenerateFursuitWebpJob($fursuit->id))->handle();
    $fursuit = $fursuit->fresh();

    // No query string to churn: the same picture keeps the same cache key forever.
    expect($fursuit->image_thumb_url)->not->toContain('?')
        ->and($fursuit->image_thumb_url)->toContain('gallery/fursuits/thumbs/original.webp')
        ->and($fursuit->image_thumb_url)->toBe($fursuit->fresh()->image_thumb_url);
});

test('the master photo stays signed even when variants are public', function () {
    config(['gallery.public_variants' => true]);

    // No variants rendered, so the accessors fall back to the private original.
    $fursuit = galleryFursuit();

    expect(Fursuit::variantUrl($fursuit->image))->toBe(Fursuit::signedStorageUrl($fursuit->image));
});
