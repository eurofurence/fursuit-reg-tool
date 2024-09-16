<?php

namespace App\Domain\Checkout\Models\Checkout\Transitions;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Cancelled;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Domain\Checkout\Services\FiskalyService;
use App\Models\Badge\States\ReadyForPickup;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class ToFinished extends Transition
{
    public function __construct(public Checkout $checkout)
    {
    }

    public function handle()
    {
        return DB::transaction(function () {
            $this->checkout->status = new Finished($this->checkout);
            $this->checkout->save();

            $fiskalyService = new FiskalyService();
            $fiskalyService->updateOrCreateTransaction($this->checkout);

            $this->checkout->items->each(function ($item) {
                if ($item->payable->status->canTransitionTo(ReadyForPickup::class)) {
                    $item->payable->status->transitionTo(ReadyForPickup::class);
                }
            });

            // if cash deposit money to cash register
            if ($this->checkout->payment_method === 'cash') {
                $this->checkout->machine->wallet->deposit($this->checkout->total);
            }

            // add money to user wallet to zero out his balance
            $this->checkout->user->wallet->deposit($this->checkout->total);

            activity()
                ->performedOn($this->checkout)
                ->causedBy(auth('machine-user')->user())
                ->log('Checkout finished');

            return $this->checkout;
        });
    }
}
