<?php

namespace App\Models;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\TseClient;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrinterStatus;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Machine describes a pos system
 */
class Machine extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    // HasApiTokens is for the native print agent. The POS browser authenticates
    // with a session, but the agent is a desktop app on a different network and
    // needs a bearer token it can hold onto.
    use Authenticatable, Authorizable, HasApiTokens, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = [
        'remember_token',
    ];

    protected $casts = [
        'should_discover_printers' => 'boolean',
        'is_print_server' => 'boolean',
        'pending_print_jobs_count' => 'integer',
        'auto_logout_timeout' => 'integer',
        'archived_at' => 'datetime',
        'agent_last_seen_at' => 'datetime',
        'badge_range_min' => 'integer',
        'badge_range_max' => 'integer',
    ];

    /**
     * Whether this desk only handles a slice of the attendee IDs. One end is
     * enough: "everything from 3000 up" is a crate too.
     */
    public function hasBadgeRange(): bool
    {
        return $this->badge_range_min !== null || $this->badge_range_max !== null;
    }

    // generic printers
    public function printers()
    {
        return $this->hasMany(Printer::class);
    }

    // checkouts
    public function checkouts()
    {
        return $this->hasMany(Checkout::class);
    }

    /**
     * The stored `tse_client_id`, kept only for the machines that were pinned to a client
     * by hand before that choice was removed.
     *
     * Nothing writes it any more and nothing should read it to decide what signs a
     * receipt; {@see self::signingTseClient()} is the answer to that question.
     */
    public function tseClient()
    {
        return $this->belongsTo(TseClient::class);
    }

    /**
     * The TSE client this till signs under: whichever one is registered.
     *
     * Machines used to name their own, which was a choice with exactly one correct answer
     * and several ways to get it wrong - a new till left unassigned signed nothing, and a
     * till still pointing at last year's deregistered client failed at the counter with a
     * queue in front of it. Only one client may be registered at a time
     * ({@see TseClient::activeClient()}), so there is nothing left to choose.
     */
    public function signingTseClient(): ?TseClient
    {
        return TseClient::activeClient();
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

    /**
     * Machines whose print agent has called in recently.
     *
     * The agent lives on a private network and reaches out to us, so "when did
     * we last hear from it" is the only liveness signal there is.
     */
    public function scopeWithAgentConnected($query)
    {
        return $query->where('agent_last_seen_at', '>', now()->subMinutes(2));
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeOnlyArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeWithArchived($query)
    {
        return $query;
    }

    // Helper methods

    /**
     * Whether the print agent on this machine is still talking to us.
     */
    public function isAgentConnected(): bool
    {
        return $this->agent_last_seen_at?->gt(now()->subMinutes(2)) ?? false;
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

    public function archive(): void
    {
        $this->archived_at = now();
        $this->save();
    }

    public function unarchive(): void
    {
        $this->archived_at = null;
        $this->save();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
