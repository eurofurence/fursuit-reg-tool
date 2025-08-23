<?php

namespace App\Domain\Printing\Models;

use App\Enum\PrintJobTypeEnum;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Printer extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory()
    {
        return \Database\Factories\PrinterFactory::new();
    }

    public function casts()
    {
        return [
            'type' => PrintJobTypeEnum::class,
            'paper_sizes' => 'array',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
