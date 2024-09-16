<?php

namespace Database\Seeders;

use App\Http\Controllers\FCEA\DashboardController;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\FCEA\UserCatch;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class FceaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Artisan::call("event:state preorder");
        $event =  Event::first();

        $users = User::factory(50)->create();
        $fursuiters = $users->random(20);

        $fursuits = Fursuit::factory(60)->recycle($fursuiters)->create();

        foreach ($fursuits as $fursuit) {
            $catchers = $users->except([$fursuit->user->id])->random(fake()->numberBetween(0, 40));

            // This is very slow, but eh it works c:
            foreach ($catchers as $catcher) {
                UserCatch::factory()
                    ->recycle($fursuit)
                    ->recycle($catcher)
                    ->recycle($event)
                    ->create();
            }
        }

        DashboardController::refreshRanking();
    }
}
