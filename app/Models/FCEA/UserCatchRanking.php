<?php

namespace App\Models\FCEA;

use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Model of the Ranking entrys for fursuits and users. Contains user_id and fursuit_id but only one of them is used at a time.
// Depends if it is a user ranking entry or fursuit ranking entry
class UserCatchRanking extends Model
{
    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fursuit(): BelongsTo
    {
        return $this->belongsTo(Fursuit::class);
    }

    public function hasUser(): bool
    {
        return $this->user_id <> null;
    }

    public function hasFursuit(): bool
    {
        return $this->fursuit_id <> null;
    }
    protected function casts(): array
    {
        return [
            'score_reached_at' => 'timestamp',
        ];
    }
}
