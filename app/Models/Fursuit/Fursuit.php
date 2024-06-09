<?php

namespace App\Models\Fursuit;

use App\Models\Fursuit\States\FursuitStatusState;
use App\Models\Species;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\ModelStates\HasStates;

class Fursuit extends Model
{
    use HasStates;
    protected $guarded = [];

    protected $casts = [
        'status' => FursuitStatusState::class
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }
}
