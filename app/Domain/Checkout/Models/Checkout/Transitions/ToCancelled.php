<?php

namespace App\Domain\Checkout\Models\Checkout\Transitions;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Cancelled;
use App\Domain\Checkout\Services\FiskalyService;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class ToCancelled extends Transition
{
    public function __construct(public Checkout $checkout)
    {
    }

    public function handle()
    {
        return DB::transaction(function () {
            $this->checkout->status = new Cancelled($this->checkout);
            $this->checkout->save();

            $fiskalyService = new FiskalyService();
            $fiskalyService->updateOrCreateTransaction($this->checkout);

            activity()
                ->performedOn($this->checkout)
                ->causedBy(auth('machine-user')->user())
                ->log('Checkout cancelled');
            return $this->checkout;
        });
    }
}
