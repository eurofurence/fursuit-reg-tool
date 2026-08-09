<?php

namespace Database\Seeders;

use App\Enum\FursuitReviewOutcomeEnum;
use App\Models\ReviewReason;
use Illuminate\Database\Seeder;

/**
 * The shipped review reasons, for a database that has none.
 *
 * Only ever inserts: an installation whose desk has edited the wording, retired a reason or
 * reordered the list keeps what it has, because the list belongs to the reviewers once they have
 * touched it. The migration seeds the same defaults on deploy while the table is empty; this
 * seeder is what `migrate:fresh --seed` and a local reset run.
 */
class ReviewReasonSeeder extends Seeder
{
    public function run(): void
    {
        foreach (FursuitReviewOutcomeEnum::cases() as $outcome) {
            foreach (ReviewReason::defaultsFor($outcome) as $reason) {
                ReviewReason::firstOrCreate(
                    ['outcome' => $reason['outcome'], 'slug' => $reason['slug']],
                    $reason,
                );
            }
        }
    }
}
