<?php

namespace App\Observers;

use App\Http\Controllers\GALLERY\GalleryController;
use App\Jobs\GenerateFursuitWebpJob;
use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\FursuitSubmissionRevision;
use App\Models\Species;
use App\Services\FursuitCatchCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class FursuitObserver
{
    /**
     * Webp paths that a photo replacement orphaned, captured in `updating` (where the old
     * value is still readable) and consumed in `updated`, keyed by fursuit id.
     *
     * @var array<int, string|null>
     */
    private static array $replacedWebp = [];

    /**
     * Same, for the grid thumbnail rendered next to it.
     *
     * @var array<int, string|null>
     */
    private static array $replacedThumb = [];

    /**
     * The photo a fursuit had before the update, so `updated` can delete the variants
     * that were derived from it. The columns alone are not enough: a model instance that
     * was loaded before the render job wrote them back reports null originals, which
     * would leak both files into the bucket.
     *
     * @var array<int, string|null>
     */
    private static array $replacedImage = [];

    /**
     * Cascade a fursuit soft-delete to its badges so they aren't left orphaned with a NULL
     * deleted_at. See docs/bugfix-04-fix.md.
     */
    public function deleting(Fursuit $fursuit): bool
    {
        // A force delete deliberately removes the row (and the FK cascade removes its badges);
        // leave that logic untouched.
        if ($fursuit->isForceDeleting()) {
            return true;
        }

        // Re-entrant soft delete on an already-trashed fursuit (e.g. the public controller's
        // redundant cleanup after the badge cascade already deleted it): abort cleanly.
        if ($fursuit->trashed()) {
            return false;
        }

        Fursuit::$isCascadingDelete = true;
        try {
            $fursuit->badges()->get()->each->delete();
        } finally {
            Fursuit::$isCascadingDelete = false;
        }

        return true;
    }

    public function created(Fursuit $fursuit): void
    {
        if ($fursuit->catch_em_all === true) {
            $fursuit->catch_code = $this->generateCatchCode();
            $fursuit->save();
        }

        if ($fursuit->image && ! $fursuit->image_webp) {
            GenerateFursuitWebpJob::dispatch($fursuit->id);
        }

        Cache::forget(self::GALLERY_FOLDER_CACHE);
    }

    /** The gallery landing page caches its per-event counts and cover photos under this key. */
    private const GALLERY_FOLDER_CACHE = GalleryController::FOLDER_CACHE_KEY;

    /** Fursuit fields the folder overview counts or illustrates. */
    private const GALLERY_FOLDER_INPUTS = ['image', 'published', 'status', 'event_id', 'user_id'];

    /**
     * A replaced photo makes the derived gallery webp wrong, not missing - which is why
     * the old accessor (generate only when the column is empty) kept serving the previous
     * picture after an approved fursuit was edited. Drop the reference here, whatever
     * write path made the change, so nothing can serve it while the re-render is queued.
     */
    public function updating(Fursuit $fursuit): void
    {
        $this->recordRevision($fursuit);

        if (! $fursuit->isDirty('image')) {
            return;
        }

        self::$replacedWebp[$fursuit->id] = $fursuit->getOriginal('image_webp');
        self::$replacedThumb[$fursuit->id] = $fursuit->getOriginal('image_thumb');
        self::$replacedImage[$fursuit->id] = $fursuit->getOriginal('image');
        $fursuit->image_webp = null;
        $fursuit->image_thumb = null;
    }

    /**
     * The three fields a reviewer judges. A change to any of them is a new submission.
     */
    private const SUBMISSION_INPUTS = ['name', 'species_id', 'image'];

    /**
     * Keep what the submission looked like before this change.
     *
     * Here rather than in the controllers because there are four write paths - the attendee
     * editor, the desk correction, the admin form and the odd console command - and a
     * reviewer needs the history whichever one was used. `updating` is the only place the
     * old values are still readable.
     *
     * The species name is snapshotted alongside the id so the entry still reads correctly
     * after a species is renamed or merged.
     */
    private function recordRevision(Fursuit $fursuit): void
    {
        if (! $fursuit->isDirty(self::SUBMISSION_INPUTS)) {
            return;
        }

        /*
         * Nothing to snapshot means this is not a revision. `created` above mints the catch
         * code and saves again, and that save fires `updating` before Eloquent has synced the
         * originals of the insert - so every field reads as dirty and every original as null,
         * which wrote a revision full of nulls for every fursuit ever created. Both columns
         * are required of an attendee, so a genuine edit can never look like this.
         */
        if ($fursuit->getOriginal('name') === null && $fursuit->getOriginal('image') === null) {
            return;
        }

        $speciesId = $fursuit->getOriginal('species_id');

        FursuitSubmissionRevision::create([
            'fursuit_id' => $fursuit->id,
            'name' => $fursuit->getOriginal('name'),
            'species_id' => $speciesId,
            'species_name' => $speciesId ? Species::whereKey($speciesId)->value('name') : null,
            'image' => $fursuit->getOriginal('image'),
            'published' => (bool) $fursuit->getOriginal('published'),
            'catch_em_all' => (bool) $fursuit->getOriginal('catch_em_all'),
            // Null when nobody is signed in, e.g. a console command or a queued job.
            'changed_by_id' => auth()->id(),
        ]);
    }

    /**
     * Fursuit fields that end up drawn on the badge. Changing any of them makes
     * every rendered PDF for that fursuit stale.
     */
    private const PRINT_FILE_INPUTS = ['name', 'species_id', 'image', 'catch_code', 'catch_em_all'];

    public function updated(Fursuit $fursuit): void
    {
        $replacedImage = self::$replacedImage[$fursuit->id] ?? null;
        $orphans = [
            self::$replacedWebp[$fursuit->id] ?? null,
            self::$replacedThumb[$fursuit->id] ?? null,
            // Derived from the previous photo's name, so this catches the variants even
            // when the columns on this instance are out of date.
            $replacedImage ? GenerateFursuitWebpJob::pathFor($replacedImage) : null,
            $replacedImage ? GenerateFursuitWebpJob::thumbPathFor($replacedImage) : null,
        ];
        unset(
            self::$replacedWebp[$fursuit->id],
            self::$replacedThumb[$fursuit->id],
            self::$replacedImage[$fursuit->id],
        );

        if ($fursuit->wasChanged('image')) {
            $current = [$fursuit->image_webp, $fursuit->image_thumb];

            foreach (array_filter($orphans) as $orphan) {
                if (! in_array($orphan, $current, true)) {
                    Storage::delete($orphan);
                }
            }

            if ($fursuit->image) {
                GenerateFursuitWebpJob::dispatch($fursuit->id);
            }
        }

        if ($fursuit->wasChanged(self::GALLERY_FOLDER_INPUTS)) {
            Cache::forget(self::GALLERY_FOLDER_CACHE);
        }

        if ($fursuit->catch_em_all === true && $fursuit->catch_code === null) {
            $fursuit->catch_code = $this->generateCatchCode();
            $fursuit->save();
        }

        // A new photo or a renamed character means the cards on file no longer
        // show what the order says. Throw them away and re-render; badges already
        // committed to a batch are frozen and skip themselves.
        if ($fursuit->wasChanged(self::PRINT_FILE_INPUTS)) {
            $fursuit->badges()->get()->each(
                fn ($badge) => GenerateBadgePrintFileJob::invalidateFor($badge)
            );
        }
    }

    public function deleted(Fursuit $fursuit): void
    {
        Cache::forget(self::GALLERY_FOLDER_CACHE);
    }

    private function generateCatchCode(): string
    {
        // Random upprecase 5 letter string that does not already exist, loop until it does not exist
        do {
            // NO 0 or O for readability
            $catch_code = (new FursuitCatchCode(Fursuit::class, 'catch_code'))->generate();
        } while (Fursuit::where('catch_code', $catch_code)->exists());

        return $catch_code;
    }
}
