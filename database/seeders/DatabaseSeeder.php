<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Reference data only. The review reasons are configuration the desk edits in Settings, so
     * a fresh database needs the shipped list to have a working review queue at all; the seeder
     * only inserts what is missing, so running this twice changes nothing.
     */
    public function run(): void
    {
        $this->call(ReviewReasonSeeder::class);
    }
}
