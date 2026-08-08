<?php

namespace App\Models\Fursuit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One superseded version of a submission, written by FursuitObserver.
 *
 * Read by the review queue so a reviewer can see what the attendee changed - including the
 * case that matters most, which is that they changed nothing and sent the same photo back.
 */
class FursuitSubmissionRevision extends Model
{
    protected $guarded = [];

    protected $casts = [
        'published' => 'boolean',
        'catch_em_all' => 'boolean',
    ];

    public function fursuit(): BelongsTo
    {
        return $this->belongsTo(Fursuit::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
