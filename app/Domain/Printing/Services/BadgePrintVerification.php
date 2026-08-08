<?php

namespace App\Domain\Printing\Services;

use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintVerificationSourceEnum;
use App\Models\Badge\Badge;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "A human looked at this card and confirmed it exists", and the undo for it.
 *
 * Two places check a card off now - the POS numpad at the desk and the inline column on
 * the admin badge list - and both have to write the same thing: the stamp goes on the
 * print job through PrintJob::markVerified() where there is one, so the batch counters
 * follow, and straight onto the badge where there is not. Getting that wrong in one of
 * the two callers means a crate that reconciles on one screen and not the other, which
 * is why the transaction lives here rather than in either controller.
 *
 * `$where` is the tail of the activity log line, so the record says which screen the
 * gesture came from.
 */
final class BadgePrintVerification
{
    /**
     * Stamp the card, through the print job where there is one.
     *
     * A badge printed before print jobs existed, or whose job was pruned, still has to be
     * checkable off, so the badge is stamped directly in that case.
     *
     * `$verifiedBy` is only ever set from the admin panel. `print_jobs.verified_by_id`
     * points at `users`, and the person at the desk is a `staff` row - the activity entry
     * is what carries who, there.
     */
    public static function verify(
        Badge $badge,
        string $where,
        ?User $verifiedBy = null,
        ?string $staff = null,
    ): void {
        DB::transaction(function () use ($badge, $where, $verifiedBy, $staff) {
            $job = $badge->printJobs()
                ->where('status', PrintJobStatusEnum::Printed)
                ->orderByDesc('printed_at')
                ->first()
                ?? $badge->printJobs()->orderByDesc('id')->first();

            if ($job) {
                $job->markVerified(PrintVerificationSourceEnum::Operator, $verifiedBy);
            } else {
                $badge->forceFill(['verified_print_at' => now()])->saveQuietly();
            }

            self::log($badge, 'Badge checked off '.$where, $staff);
        });
    }

    /**
     * Undo one check-off, for the card typed as `1234-1` that was `1234-2`.
     *
     * Clears the stamp on the badge and on its print jobs, so the card falls back into the
     * unverified list and the batch counters agree with it again.
     */
    public static function revert(Badge $badge, string $where, ?string $staff = null): void
    {
        DB::transaction(function () use ($badge, $where, $staff) {
            $badge->printJobs()
                ->whereNotNull('verified_print_at')
                ->get()
                ->each(function (PrintJob $job) {
                    $job->update([
                        'verified_print_at' => null,
                        'verification_source' => null,
                        'verified_by_id' => null,
                    ]);

                    $job->batch?->recalculateCounters();
                });

            $badge->forceFill(['verified_print_at' => null])->saveQuietly();

            self::log($badge, 'Badge check-off reverted '.$where, $staff);
        });
    }

    /**
     * The POS runs on the `machine-user` guard, which the activity log does not resolve a
     * causer from, so the desk passes the staff name as a property. The admin panel is on
     * the web guard and the causer is filled in for it.
     */
    private static function log(Badge $badge, string $message, ?string $staff): void
    {
        activity()
            ->performedOn($badge)
            ->withProperties($staff === null ? [] : ['staff' => $staff])
            ->log($message);
    }
}
