<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Active;
use App\Domain\Checkout\Models\Checkout\States\Cancelled;
use App\Models\Badge\Badge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Building and rebuilding a till transaction.
 *
 * Extracted from CheckoutController so a price override can rebuild a
 * transaction the same way the desk creates one. A checkout carries a signed
 * Fiskaly transaction, so its total cannot be edited in place: the old one is
 * cancelled with an end signature and a fresh one is opened at the new price.
 */
class CheckoutService
{
    /**
     * Open a transaction for the given badges, cancelling whatever this machine
     * had open. Only one checkout is ever ACTIVE per machine.
     *
     * @param  Collection<int, Badge>  $badges
     */
    public function create(Collection $badges, int $userId): Checkout
    {
        $this->cancelActiveForMachine();

        $checkout = DB::transaction(function () use ($badges, $userId) {
            $checkout = Checkout::create([
                'remote_id' => Str::uuid(),
                'remote_rev_count' => 1,
                'status' => 'ACTIVE',
                'user_id' => $userId,
                'cashier_id' => auth('machine-user')->id(),
                'machine_id' => auth('machine')->user()->id,
                'total' => $badges->sum('total'),
                'tax' => $badges->sum('tax'),
                'subtotal' => $badges->sum('subtotal'),
                'fiskaly_data' => [],
            ]);

            foreach ($badges as $badge) {
                $checkout->items()->create([
                    'payable_type' => Badge::class,
                    'payable_id' => $badge->id,
                    'name' => 'Fursuit Badge',
                    'description' => $this->describe($badge),
                    'total' => $badge->total,
                    'tax' => $badge->tax,
                    'subtotal' => $badge->subtotal,
                ]);
            }

            return $checkout;
        });

        (new FiskalyService)->updateOrCreateTransaction($checkout);

        return $checkout;
    }

    /**
     * Reopen a transaction against the current price of the badges it held.
     *
     * The badge set is read back off the old checkout rather than passed in, so
     * a rebuild can never quietly add or drop a line.
     */
    public function rebuild(Checkout $checkout): Checkout
    {
        $badgeIds = $checkout->items()
            ->where('payable_type', Badge::class)
            ->pluck('payable_id');

        $badges = Badge::whereIn('id', $badgeIds)->get();

        return $this->create($badges, $checkout->user_id);
    }

    /**
     * @return array<int, string>
     */
    private function describe(Badge $badge): array
    {
        $features = [];

        if ($badge->dual_side_print) {
            $features[] = 'Double Sided Print';
        }

        if ($badge->extra_copy_of) {
            $features[] = 'Extra Copy';
        }

        return $features;
    }

    /**
     * Cancel every ACTIVE checkout on this machine, forcing the row to CANCELLED
     * if Fiskaly refuses - a stuck ACTIVE row would block every later sale.
     */
    public function cancelActiveForMachine(): void
    {
        $machineId = auth('machine')->user()->id;

        $activeCheckouts = Checkout::where('machine_id', $machineId)
            ->where('status', Active::$name)
            ->get();

        foreach ($activeCheckouts as $activeCheckout) {
            try {
                $activeCheckout->refresh();

                if ($activeCheckout->status instanceof Cancelled) {
                    continue;
                }

                if ($activeCheckout->status->canTransitionTo(Cancelled::class)) {
                    $activeCheckout->status->transitionTo(Cancelled::class);
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to cancel stale checkout', [
                    'checkout_id' => $activeCheckout->id,
                    'error' => $e->getMessage(),
                ]);

                try {
                    $activeCheckout->update(['status' => Cancelled::$name]);
                } catch (\Exception $updateException) {
                    \Log::error('Could not force cancel checkout', [
                        'checkout_id' => $activeCheckout->id,
                        'error' => $updateException->getMessage(),
                    ]);
                }
            }
        }
    }
}
