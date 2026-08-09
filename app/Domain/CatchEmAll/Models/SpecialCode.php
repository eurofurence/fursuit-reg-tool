<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\SpecialCodeAction;
use App\Domain\CatchEmAll\SpecialActions\SpecialActionsRegister;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpecialCode extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'constructor_data' => 'array',
        'type' => SpecialCodeType::class,
    ];

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

    /**
     * Create an instance of the action class with the stored constructor data.
     */
    public function createActionInstance(): SpecialCodeAction
    {
        $className = $this->type instanceof SpecialCodeType
            ? SpecialActionsRegister::getClassForSpecialCodeType($this->type)
            : null;

        if (($className === null || $className === '') && is_string($this->class_name)) {
            $className = $this->class_name;
        }

        if (! is_string($className) || $className === '') {
            $type = $this->type instanceof SpecialCodeType ? $this->type->name : 'none';

            throw new \InvalidArgumentException("No action class could be resolved for special code type '{$type}'.");
        }

        if (! class_exists($className)) {
            throw new \InvalidArgumentException("Class {$className} does not exist.");
        }

        return new $className(
            $this->event_id,
            $this->code,
            $this->constructor_data
        );
    }
}
