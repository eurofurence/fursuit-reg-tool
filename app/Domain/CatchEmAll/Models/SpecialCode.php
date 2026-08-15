<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\SpecialCodeAction;
use App\Domain\CatchEmAll\SpecialActions\SpecialActionsRegister;
use App\Models\Event;
use App\Models\EventUser;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpecialCode extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'type' => SpecialCodeType::class,
    ];

    protected function constructorData(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): mixed {
                if ($value === null || $value === '') {
                    return null;
                }

                if (is_string($value)) {
                    return json_decode($value);
                }

                return $value;
            },
            set: function (mixed $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            },
        );
    }

    /**
     * Get the event that owns the special code.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function userSpecialCatches(): HasMany
    {
        return $this->hasMany(UserSpecialCatch::class);
    }

    public function eventUsers(): BelongsToMany
    {
        return $this->belongsToMany(EventUser::class, 'special_code_connection', 'special_code_id', 'event_users_id')
            ->withTimestamps();
    }

    /**
     * Create an instance of the action class with the stored constructor data.
     */
    public function createActionInstance(): SpecialCodeAction
    {
        $className = SpecialActionsRegister::getClassForSpecialCodeType($this->type);
        $constructorData = $this->constructor_data;

        if (is_object($constructorData)) {
            $constructorData = get_object_vars($constructorData);
        }

        return new $className(
            $this->event_id,
            $this->code,
            $constructorData
        );
    }
}
