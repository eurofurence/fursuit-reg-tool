<?php

namespace App\Observers;

use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;

class BadgeObserver
{
    /**
     * Keep the soft-delete state consistent between a badge and its connected rows
     * (spare copies + the parent fursuit). Fires for soft deletes only — a force delete
     * deliberately removes the row, so it is left untouched. See docs/bugfix-04-fix.md.
     */
    public function deleted(Badge $badge): void
    {
        if ($badge->isForceDeleting()) {
            return;
        }

        // When a whole fursuit is cascading, it already soft-deletes every one of its badges
        // (main + copies), so skip per-badge cascading to avoid redundant work / recursion.
        if (Fursuit::$isCascadingDelete) {
            return;
        }

        // Soft-delete the spare copies of this main badge so they aren't left orphaned.
        // newQuery() excludes already soft-deleted copies, so this is idempotent.
        if ($badge->extra_copy_of === null) {
            $badge->newQuery()
                ->where('extra_copy_of', $badge->id)
                ->get()
                ->each
                ->delete();
        }

        // If the parent fursuit no longer has any active badge, soft-delete it too.
        $fursuit = $badge->fursuit;
        if ($fursuit && ! $fursuit->trashed() && $fursuit->badges()->count() === 0) {
            $fursuit->delete();
        }
    }

    /**
     * Badge fields that end up drawn on the card. Changing any of them makes the
     * rendered PDF stale.
     */
    private const PRINT_FILE_INPUTS = ['custom_id', 'dual_side_print'];

    public function updated(Badge $badge): void
    {
        if ($badge->wasChanged(self::PRINT_FILE_INPUTS)) {
            GenerateBadgePrintFileJob::invalidateFor($badge);
        }

        // Based on tax_rate, calculate tax and update subtotal
        if ($badge->isDirty('total')) {
            $badge->subtotal = round($badge->total / 1.19);
            $badge->tax = round($badge->total - $badge->subtotal);

            $badge->saveQuietly();
        }
    }
}
