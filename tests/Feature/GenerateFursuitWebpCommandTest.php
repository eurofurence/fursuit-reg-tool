<?php

use App\Jobs\GenerateFursuitWebpJob;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function fursuitWithPhoto(string $file, array $overrides = []): Fursuit
{
    Storage::put('fursuits/'.$file, UploadedFile::fake()->image($file, 120, 160)->get());

    return Fursuit::create(array_merge([
        'user_id' => User::factory()->create()->id,
        'event_id' => Event::factory()->create()->id,
        'species_id' => Species::firstOrCreate(['name' => 'Wolf'], ['checked' => true])->id,
        'name' => 'Suit '.$file,
        'image' => 'fursuits/'.$file,
        'status' => 'approved',
        'published' => true,
        'catch_em_all' => false,
    ], $overrides));
}

test('--forget-missing clears only the columns this bucket cannot back up', function () {
    Queue::fake();

    // Rendered here: both files present, so the row is trustworthy.
    $present = fursuitWithPhoto('present.jpg');
    Storage::put('gallery/fursuits/present.webp', 'webp');
    Storage::put('gallery/fursuits/thumbs/present.webp', 'thumb');
    $present->forceFill([
        'image_webp' => 'gallery/fursuits/present.webp',
        'image_thumb' => 'gallery/fursuits/thumbs/present.webp',
    ])->saveQuietly();

    // The shape a database restored from another environment leaves: paths, no objects.
    $restored = fursuitWithPhoto('restored.jpg');
    $restored->forceFill([
        'image_webp' => 'gallery/fursuits/restored.webp',
        'image_thumb' => 'gallery/fursuits/thumbs/restored.webp',
    ])->saveQuietly();

    Queue::fake(); // creating the fixtures above already dispatched their own renders
    artisan('fursuits:generate-webp --forget-missing')->assertSuccessful();

    expect($present->fresh()->image_webp)->toBe('gallery/fursuits/present.webp')
        ->and($present->fresh()->image_thumb)->toBe('gallery/fursuits/thumbs/present.webp')
        ->and($restored->fresh()->image_webp)->toBeNull()
        ->and($restored->fresh()->image_thumb)->toBeNull();

    // Only the cleared row is worth rendering.
    Queue::assertPushed(GenerateFursuitWebpJob::class, 1);
    Queue::assertPushed(GenerateFursuitWebpJob::class,
        fn (GenerateFursuitWebpJob $job) => $job->fursuitId === $restored->id);
});

test('a cleared column withdraws the fursuit from the gallery instead of serving a dead link', function () {
    Queue::fake();

    $fursuit = fursuitWithPhoto('dead.jpg');
    $fursuit->forceFill([
        'image_webp' => 'gallery/fursuits/dead.webp',
        'image_thumb' => 'gallery/fursuits/thumbs/dead.webp',
    ])->saveQuietly();

    Queue::fake(); // creating the fixtures above already dispatched their own renders
    artisan('fursuits:generate-webp --forget-missing')->assertSuccessful();

    // No URL at all rather than one pointing at a missing object - and no master
    // fallback either, since the gallery must never serve the print-quality original.
    expect($fursuit->fresh()->image_webp_url)->toBeNull()
        ->and($fursuit->fresh()->image_thumb_url)->toBeNull();

    get(route('gallery.all'))->assertInertia(fn ($page) => $page->has('fursuits', 0));
});

test('--forget-all clears every variant column', function () {
    Queue::fake();

    $fursuit = fursuitWithPhoto('keeper.jpg');
    Storage::put('gallery/fursuits/keeper.webp', 'webp');
    Storage::put('gallery/fursuits/thumbs/keeper.webp', 'thumb');
    $fursuit->forceFill([
        'image_webp' => 'gallery/fursuits/keeper.webp',
        'image_thumb' => 'gallery/fursuits/thumbs/keeper.webp',
    ])->saveQuietly();

    Queue::fake(); // creating the fixtures above already dispatched their own renders
    artisan('fursuits:generate-webp --forget-all')->assertSuccessful();

    expect($fursuit->fresh()->image_webp)->toBeNull()
        ->and($fursuit->fresh()->image_thumb)->toBeNull();

    Queue::assertPushed(GenerateFursuitWebpJob::class, 1);
});

test('--dry-run leaves the columns alone', function () {
    Queue::fake();

    $fursuit = fursuitWithPhoto('untouched.jpg');
    $fursuit->forceFill(['image_webp' => 'gallery/fursuits/gone.webp'])->saveQuietly();

    Queue::fake(); // creating the fixtures above already dispatched their own renders
    artisan('fursuits:generate-webp --forget-all --dry-run')->assertSuccessful();

    expect($fursuit->fresh()->image_webp)->toBe('gallery/fursuits/gone.webp');

    Queue::assertNothingPushed();
});
