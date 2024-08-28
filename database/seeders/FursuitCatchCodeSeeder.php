<?php

namespace Database\Seeders;

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\FCEA\UserFursuitCatch;
use App\Models\Fursuit\Fursuit;
use Illuminate\Database\Seeder;

class FursuitCatchCodeSeeder extends Seeder
{
    // Code which should be run once to generate all missing codes
    // Further Fursuit Creation/Updates should be caught by an observer
    // Will fill catch_em_all_code of fursuits who choose to participate
    public function run(): void
    {
        $purgeAllCodes = false; // disabled as you might want keep codes (especially if badges are printed)
        $purgeCodesOfNonParticipants = false; // disabled as you might want to keep codes if users reenter the system at later time

        // to purge all codes and build new
        if ($purgeAllCodes)
            Fursuit::query()->update(['catch_code' => null]);

        // removing of codes of these who unregistered from the system
        if ($purgeCodesOfNonParticipants)
            Fursuit::where('catch_em_all', 0)->update(['catch_code' => null]);

        // Iterate and save manually to trigger Fursuit::saving()
        foreach (Fursuit::all() as $fursuit) {
            if (!$fursuit->catch_em_all || $fursuit->catch_code <> null)
                continue;

            $fursuit->save();
        }
    }
}
