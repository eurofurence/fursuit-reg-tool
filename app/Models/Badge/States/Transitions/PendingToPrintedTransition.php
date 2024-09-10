<?php

namespace App\Models\Badge\States\Transitions;

use App\Models\Badge\Badge;
use App\Models\Badge\States\Printed;
use App\Notifications\FursuitApprovedNotification;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class PendingToPrintedTransition extends Transition
{
    public function __construct(public Badge $badge)
    {
    }

    public function handle()
    {
        return DB::transaction(function () {
            $this->badge->status = new Printed($this->badge);
            $this->badge->printed_at = now();
            $this->badge->save();
            activity()
                ->performedOn($this->badge)
                ->log('Fursuit Printed');
            return $this->badge;
        });
    }
}
