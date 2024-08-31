<?php

namespace App\Models\FCEA;

use App\Models\Fursuit\Fursuit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class userCatchFursuitRanking extends Model
{
    public $timestamps = false;

    public function fursuit(): BelongsTo
    {
        return $this->belongsTo(Fursuit::class);
    }

    protected function casts(): array
    {
        return [
            'score_reached_at' => 'timestamp',
        ];
    }
}
