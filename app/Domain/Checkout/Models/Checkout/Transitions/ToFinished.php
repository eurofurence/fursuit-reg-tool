<?php

namespace App\Domain\Checkout\Models\Checkout\Transitions;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Domain\Checkout\Services\FiskalyService;
use App\Jobs\CreateReceiptFromCheckoutJob;
use App\Models\Badge\State_Payment\Paid;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class ToFinished extends Transition
{
    public function __construct(public Checkout $checkout) {}

    public function handle()
    {
        return DB::transaction(function () {
            $this->checkout->status = new Finished($this->checkout);
            $this->checkout->save();

            $fiskalyService = new FiskalyService;
            $fiskalyService->finishTransaction($this->checkout);

            $this->checkout->items->each(function ($item) {
                if ($item->payable->status_payment->canTransitionTo(Paid::class)) {
                    $item->payable->status_payment->transitionTo(Paid::class);
                }
            });

            activity()
                ->performedOn($this->checkout)
                ->causedBy(auth('machine-user')->user())
                ->log('Checkout finished');

            // Generate receipt asynchronously after checkout is finished
            CreateReceiptFromCheckoutJob::dispatch($this->checkout);

            return $this->checkout;
        });
    }
}
