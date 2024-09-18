<?php

namespace App\Observers;

use App\Domain\Checkout\Models\TseClient;
use App\Domain\Checkout\Services\FiskalyService;

class TseClientsObserver
{
    public function created(TseClient $tseClient): void
    {
        $fiskalyService = new FiskalyService();
        $fiskalyService->createClient($tseClient);
    }

    public function updated(TseClient $tseClient): void
    {
        $fiskalyService = new FiskalyService();
        $fiskalyService->updateClient($tseClient);
    }
}
