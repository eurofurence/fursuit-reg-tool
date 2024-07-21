<?php

namespace App\Console\Commands;

use App\Models\Badge\Badge;
use App\Models\Badge\States\Printed;
use Illuminate\Console\Command;

class BadgesPrintCommand extends Command
{
    protected $signature = 'badges:print';

    protected $description = 'Command description';

    public function handle(): void
    {
        // Prints all badges
        Badge::whereNull('printed_at')->update([
            'status' => Printed::$name,
            'printed_at' => now(),
        ]);
    }
}
