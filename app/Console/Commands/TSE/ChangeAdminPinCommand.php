<?php

namespace App\Console\Commands\TSE;

use App\Domain\Checkout\Services\FiskalyService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ChangeAdminPinCommand extends Command
{
    protected $signature = 'tse:change-admin-pin';

    protected $description = 'Command description';

    public function handle(): void
    {
        $oldPin = $this->ask('Please enter the old admin pin');
        $newPin = Str::random();

        $fiskalyService = new FiskalyService;
        if ($fiskalyService->changeAdminPin($oldPin, $newPin)) {
            $this->info('Admin pin changed successfully.');
            $this->info('------------------------------------------------');
            $this->info('New Admin PIN: '.$newPin);
            $this->info('------------------------------------------------');
            $this->info('Please update the new admin pin in your .env file.');
        } else {
            $this->error('Failed to change admin pin.');
        }
    }
}
