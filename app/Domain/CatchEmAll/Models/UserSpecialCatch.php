<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Models\EventUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class UserSpecialCatch extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'event_user_id',
        'special_code_id',
        'type',
    ];

    protected $casts = [
        'type' => SpecialCodeType::class,
    ];

    public function eventUser(): BelongsTo
    {
        return $this->belongsTo(EventUser::class);
    }

    public function specialCode(): BelongsTo
    {
        return $this->belongsTo(SpecialCode::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['event_user_id', 'special_code_id', 'type']);
    }
}
