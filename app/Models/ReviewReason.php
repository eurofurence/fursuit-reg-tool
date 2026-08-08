<?php

namespace App\Models;

use App\Enum\FursuitReviewOutcomeEnum;
use App\Services\FursuitReviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One reason a reviewer can pick, editable in Settings > Review Reasons.
 *
 * `keyword` is what the review queue puts on a chip; `body` is the paragraph the attendee
 * receives. The reviewer may still edit the body before it goes out - the chip is a starting
 * point, which is the behaviour the old modal had - so the text on the decision row is what was
 * actually sent, not a foreign key to this table.
 *
 * @property FursuitReviewOutcomeEnum $outcome
 */
class ReviewReason extends Model
{
    protected $guarded = [];

    protected $casts = [
        'outcome' => FursuitReviewOutcomeEnum::class,
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeForOutcome(Builder $query, FursuitReviewOutcomeEnum $outcome): Builder
    {
        return $query->where('outcome', $outcome->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The picker for one outcome, in the order the desk arranged it.
     *
     * `sort_order` then `id`, so two reasons that were never reordered still come back in a
     * stable order rather than whatever the driver returns.
     *
     * @return Collection<int, ReviewReason>
     */
    public static function pickerFor(FursuitReviewOutcomeEnum $outcome)
    {
        return static::query()
            ->forOutcome($outcome)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The shipped defaults for one outcome, as rows.
     *
     * Read by the seeder, by the migration that seeds an installation with an empty table, and
     * by the settings screen's "restore the defaults" action.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaultsFor(FursuitReviewOutcomeEnum $outcome): array
    {
        $order = 0;

        return collect(FursuitReviewService::DEFAULT_REASONS[$outcome->value] ?? [])
            ->map(fn (array $reason, string $slug) => [
                'outcome' => $outcome->value,
                'slug' => $slug,
                'keyword' => $reason['keyword'],
                'body' => $reason['body'],
                'sort_order' => $order += 10,
                'is_active' => true,
            ])
            ->values()
            ->all();
    }
}
