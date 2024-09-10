<?php

namespace App\Observers;

use App\Models\Fursuit\Fursuit;

class FursuitObserver
{
    public function created(Fursuit $fursuit): void
    {
        if($fursuit->catch_em_all === true) {
            $fursuit->catch_code = $this->generateCatchCode();
            $fursuit->save();
        }
    }

    public function updated(Fursuit $fursuit): void
    {
        if($fursuit->catch_em_all === true && $fursuit->catch_code === null) {
            $fursuit->catch_code = $this->generateCatchCode();
            $fursuit->save();
        }
    }

    private function generateCatchCode(): string
    {
        // Random upprecase 5 letter string that does not already exist, loop until it does not exist
        do {
            // NO 0 or O for readability
            $catch_code = strtoupper(substr(str_shuffle(str_repeat('ABCDEFGHIJKLMNPQRSTUVWXYZ123456789', 5)), 0, 5));
        } while (Fursuit::where('catch_code', $catch_code)->exists());
        return $catch_code;
    }
}
