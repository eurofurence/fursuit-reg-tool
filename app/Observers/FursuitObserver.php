<?php

namespace App\Observers;

use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Models\Fursuit\Fursuit;
use App\Services\FursuitCatchCode;

class FursuitObserver
{
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
    }

    /**
     * Fursuit fields that end up drawn on the badge. Changing any of them makes
     * every rendered PDF for that fursuit stale.
     */
    private const PRINT_FILE_INPUTS = ['name', 'species_id', 'image', 'catch_code', 'catch_em_all'];

    public function updated(Fursuit $fursuit): void
    {
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
