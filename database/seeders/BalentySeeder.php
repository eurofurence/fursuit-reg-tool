<?php

namespace Database\Seeders;

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\FCEA\UserCatch;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class BalentySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Artisan::call("event:state preorder");
        $event =  Event::first();
//        Badge::factory(30)->recycle($event)->create();
        UserCatch::factory(30)->recycle($event)->create();
    }
}
