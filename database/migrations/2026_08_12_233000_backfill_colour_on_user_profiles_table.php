<?php

use App\Models\UserProfile\UserProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every existing profile a colour.
 *
 * The column landed nullable and null meant "derive it from the fursuit", which
 * does not survive contact with reality: a profile can own several fursuits or
 * none. Every row gets its own colour instead, picked at random once.
 *
 * The WHERE guard is what makes this converge: rerunning it leaves the colours
 * people have already picked alone, and only fills rows that still have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keys = array_keys(UserProfile::PALETTE);

        DB::table('user_profiles')
            ->whereNull('colour')
            ->orderBy('id')
            ->chunkById(500, function ($profiles) use ($keys) {
                foreach ($profiles as $profile) {
                    DB::table('user_profiles')
                        ->where('id', $profile->id)
                        ->whereNull('colour')
                        ->update(['colour' => $keys[array_rand($keys)]]);
                }
            });
    }

    public function down(): void
    {
        // Colours are a user choice by the time this runs; clearing them would
        // throw away preferences to undo a backfill.
    }
};
