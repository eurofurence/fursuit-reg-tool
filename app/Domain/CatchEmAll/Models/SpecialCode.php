<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Interface\SpecialCodeAction;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialCode extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'constructor_data' => 'object',
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
     *
     * @return \App\Domain\CatchEmAll\Interface\SpecialCodeAction
     */
    public function createActionInstance(): SpecialCodeAction
    {
        $className = $this->class_name;

        if (!class_exists($className)) {
            throw new \InvalidArgumentException("Class {$className} does not exist.");
        }

        return new $className(
            $this->event_id,
            $this->code,
            $this->constructor_data
        );
    }
}
