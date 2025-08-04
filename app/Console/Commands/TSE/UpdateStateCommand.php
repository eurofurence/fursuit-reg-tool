<?php

namespace App\Console\Commands\TSE;

use App\Domain\Checkout\Services\FiskalyService;
use Illuminate\Console\Command;

class UpdateStateCommand extends Command
{
    protected $signature = 'tse:update-state {state}';

    protected $description = 'Command description';

    public function handle(): void
    {
        $state = $this->argument('state');
        $fiskalyService = new FiskalyService;
        $fiskalyService->updateTssState($state);
    }
}
