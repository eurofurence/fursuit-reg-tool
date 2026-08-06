<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\SpecialCodeAction;
use App\Domain\CatchEmAll\SpecialActions\SpecialActionsRegister;
use App\Models\Event;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialCode extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'constructor_data' => AsArrayObject::class,
        'type' => SpecialCodeType::class,
    ];

    /**
     * Get the event that owns the special code.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Create an instance of the action class with the stored constructor data.
     */
    public function createActionInstance(): SpecialCodeAction
    {
        $className = SpecialActionsRegister::getClassForSpecialCodeType($this->type);

        return new $className(
            $this->event_id,
            $this->code,
            $this->constructor_data
        );
    }
}
