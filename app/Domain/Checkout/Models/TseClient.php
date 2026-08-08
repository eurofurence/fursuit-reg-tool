<?php

namespace App\Domain\Checkout\Models;

use App\Domain\Checkout\Enums\TseClientStateEnum;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Model;

class TseClient extends Model
{
    protected $guarded = [];

    protected $casts = [
        'state' => TseClientStateEnum::class,
    ];

    public function machine()
    {
        return $this->hasOne(Machine::class);
    }

    /**
     * Clients the TSS will currently sign under.
     */
    public function scopeRegistered($query)
    {
        return $query->where('state', TseClientStateEnum::REGISTERED);
    }

    public function scopeDeregistered($query)
    {
        return $query->where('state', TseClientStateEnum::DEREGISTERED);
    }

    /**
     * The raw column rather than `$this->state`: `tse_clients.state` is a plain string and
     * the cast's `from()` throws a ValueError on any value the enum does not know. A row
     * this application did not write - `INITIALIZED`, say - must not 500 the list, so an
     * unrecognised state simply is not REGISTERED.
     */
    public function isRegistered(): bool
    {
        $raw = $this->getAttributes()['state'] ?? null;

        if ($raw instanceof TseClientStateEnum) {
            return $raw === TseClientStateEnum::REGISTERED;
        }

        return $raw === TseClientStateEnum::REGISTERED->value;
    }

    /**
     * The one already signing, if there is one.
     *
     * Exactly one client may be REGISTERED at a time. Every signed receipt carries the
     * serial of the client that signed it, and a second live client means two serials in
     * circulation for the same till with nothing recording which was in use when - the
     * traceability KassenSichV asks for, gone, and not recoverable afterwards. Fiskaly
     * also bills per registered client, so a spare left switched on costs money for the
     * rest of the year.
     *
     * The normal move between conventions is to deregister last year's client and
     * register it again the next, rather than issuing a new one.
     */
    public static function activeClient(?int $exceptId = null): ?self
    {
        return static::query()
            ->registered()
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->first();
    }
}
