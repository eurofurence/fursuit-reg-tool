<?php

namespace App\Models\Badge;

use App\Models\Badge\States\BadgeStatusState;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\FursuitStatusState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\ModelStates\HasStates;

class Badge extends Model
{
    use HasStates;

    protected $guarded = [];
    protected $casts = [
        'status' => BadgeStatusState::class,
        'extra_copy' => 'boolean',
        'dual_side_print' => 'boolean',
    ];

    public function fursuit(): BelongsTo
    {
        return $this->belongsTo(Fursuit::class);
    }

    protected function casts()
    {
        return [
            'picked_up_at' => 'datetime',
        ];
    }
}
