<?php

namespace App\Console\Commands;

use App\Models\Fursuit\Fursuit;
use Illuminate\Console\Command;

class FursuitCreateCatchCodeCommand extends Command
{
    protected $signature = 'fursuit:create-catch-code';

    protected $description = 'Command description';

    // Code which should be run once to generate all missing codes
    // Further Fursuit Creation/Updates should be caught by an observer
    // Will fill catch_em_all_code of fursuits who choose to participate
    public function handle(): void
    {
        $purgeAllCodes = false; // disabled as you might want keep codes (especially if badges are printed)
        $purgeCodesOfNonParticipants = false; // disabled as you might want to keep codes if users reenter the system at later time

        // to purge all codes and build new
        if ($purgeAllCodes)
            Fursuit::query()->update(['catch_code' => null]);

        // removing of codes of these who unregistered from the system
        if ($purgeCodesOfNonParticipants)
            Fursuit::where('catch_em_all', 0)->update(['catch_code' => null]);

        $counter = 0;
        // Iterate and save manually to trigger Fursuit::saving()
        foreach (Fursuit::all() as $fursuit) {
            if (!$fursuit->catch_em_all || $fursuit->catch_code <> null)
                continue;

            $counter++;
            $fursuit->save();
        }

        $this->info($counter.' Fursuit Catch Codes created!');
    }
}
