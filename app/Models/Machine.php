<?php

namespace App\Models;

use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrinterStatus;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\QzConnectionStatusEnum;
use Bavix\Wallet\Traits\HasWalletFloat;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;

/**
 * Machine describes a pos system
 */
class Machine extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use Authenticatable, Authorizable, HasFactory, HasWalletFloat;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = [
        'remember_token',
    ];

    protected $casts = [
        'should_discover_printers' => 'boolean',
        'is_print_server' => 'boolean',
        'qz_connection_status' => QzConnectionStatusEnum::class,
        'qz_last_seen_at' => 'datetime',
        'pending_print_jobs_count' => 'integer',
        'auto_logout_timeout' => 'integer',
    ];

    // generic printers
    public function printers()
    {
        return $this->hasMany(Printer::class);
    }

    // checkouts
    public function checkouts()
    {
        return $this->hasMany(\App\Domain\Checkout\Models\Checkout\Checkout::class);
    }

    // tse client
    public function tseClient()
    {
        return $this->belongsTo(\App\Domain\Checkout\Models\TseClient::class);
    }

    // sumupReader
    public function sumupReader()
    {
        return $this->belongsTo(SumUpReader::class);
    }

    // New relationships
    public function processingPrintJobs()
    {
        return $this->hasMany(PrintJob::class, 'processing_machine_id');
    }

    public function printerStatuses()
    {
        return $this->hasMany(PrinterStatus::class);
    }

    // Scopes
    public function scopePrintServers($query)
    {
        return $query->where('is_print_server', true);
    }

    public function scopeWithQzConnected($query)
    {
        return $query->where('qz_connection_status', QzConnectionStatusEnum::Connected);
    }

    // Helper methods
    public function isQzConnected(): bool
    {
        return $this->qz_connection_status === QzConnectionStatusEnum::Connected &&
               $this->qz_last_seen_at?->gt(now()->subMinutes(2));
    }

    public function updateQzStatus(QzConnectionStatusEnum $status): void
    {
        $this->update([
            'qz_connection_status' => $status,
            'qz_last_seen_at' => now(),
        ]);
    }

    public function getPendingPrintJobsCount(): int
    {
        return PrintJob::whereHas('printer', fn ($q) => $q->where('machine_id', $this->id))
            ->whereIn('status', [
                PrintJobStatusEnum::Pending,
                PrintJobStatusEnum::Queued,
                PrintJobStatusEnum::Retrying,
            ])
            ->count();
    }
}
