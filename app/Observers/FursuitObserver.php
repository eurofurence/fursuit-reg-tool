<?php

namespace App\Observers;

use App\Models\Fursuit\Fursuit;
use App\Services\FursuitCatchCode;

class FursuitObserver
{
    public function created(Fursuit $fursuit): void
    {
        if ($fursuit->catch_em_all === true) {
            $fursuit->catch_code = $this->generateCatchCode();
            $fursuit->save();
        }
    }

    public function updated(Fursuit $fursuit): void
    {
        if ($fursuit->catch_em_all === true && $fursuit->catch_code === null) {
            $fursuit->catch_code = $this->generateCatchCode();
            $fursuit->save();
        }

        // Note: Fursuit layers are no longer cached, so no cache clearing needed
    }

    private function generateCatchCode(): string
    {
        // Random upprecase 5 letter string that does not already exist, loop until it does not exist
        do {
            // NO 0 or O for readability
            $catch_code = (new FursuitCatchCode(Fursuit::class, 'catch_code'))->generate();
        } while (Fursuit::where('catch_code', $catch_code)->exists());

        return $catch_code;
    }
}
