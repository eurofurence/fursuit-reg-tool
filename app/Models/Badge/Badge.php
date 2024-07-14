<?php

namespace App\Models\Badge;

use App\Models\Badge\States\BadgeStatusState;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\FursuitStatusState;
use Bavix\Wallet\Interfaces\Customer;
use Bavix\Wallet\Interfaces\ProductInterface;
use Bavix\Wallet\Traits\HasWalletFloat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\ModelStates\HasStates;

class Badge extends Model implements ProductInterface
{
    use HasStates, SoftDeletes, HasWalletFloat;

    protected $guarded = [];
    protected $casts = [
        'status' => BadgeStatusState::class,
        'extra_copy' => 'boolean',
        'dual_side_print' => 'boolean',
        'apply_late_fee' => 'boolean',
        'printed_at' => 'datetime',
        'ready_for_pickup_at' => 'datetime',
        'picked_up_at' => 'datetime',
    ];

    public function fursuit(): BelongsTo
    {
        return $this->belongsTo(Fursuit::class);
    }

    public function getAmountProduct(Customer $customer): int|string
    {
        return $this->total;
    }

    public function getMetaProduct(): ?array
    {
        // Title Generator
        $features = [];
        if($this->dual_side_print) {
            $features[] = 'Double Sided Print';
        }
        if($this->extra_copy_of) {
            $features[] = 'Extra Copy';
        }
        $append = '';
        if(count($features) > 0) {
            $append = ' with Extras (' . implode(', ', $features) . ')';
        }
        return [
            'title' => 'Fursuit Badge',
            'description' => 'Purchase of Fursuit Badge #' . $this->id . $append,
        ];
    }

    protected function casts()
    {
        return [
            'picked_up_at' => 'datetime',
        ];
    }
}
