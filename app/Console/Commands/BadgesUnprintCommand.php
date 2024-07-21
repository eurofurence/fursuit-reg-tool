<?php

namespace App\Console\Commands;

use App\Models\Badge\Badge;
use App\Models\Badge\States\Pending;
use Illuminate\Console\Command;

class BadgesUnprintCommand extends Command
{
    protected $signature = 'badges:unprint';

    protected $description = 'Command description';

    public function handle(): void
    {
        // Unprint all badges
        Badge::whereNotNull('printed_at')->update([
            'status' => Pending::$name,
            'printed_at' => null,
        ]);
    }
}
