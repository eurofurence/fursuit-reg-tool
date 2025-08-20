<?php

namespace App\Domain\Printing\Models;

use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use Illuminate\Database\Eloquent\Model;

class PrintJob extends Model
{
    protected $guarded = [];

    public function printable()
    {
        return $this->morphTo();
    }

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }

    protected function casts(): array
    {
        return [
            'printed_at' => 'datetime',
            'type' => PrintJobTypeEnum::class,
            'status' => PrintJobStatusEnum::class,
        ];
    }
}
