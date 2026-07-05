<?php

namespace Database\Seeders;

use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class FceaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Artisan::call('event:state pre-order');
        $event = Event::first();

        $users = User::factory(50)->create();
        $fursuiters = $users->random(20);

        $fursuits = Fursuit::factory(60)->recycle($fursuiters)->recycle($event)->create();

        foreach ($fursuits as $fursuit) {
            Badge::factory()->for($fursuit)->create();
        }

        $eventUsers = $users->mapWithKeys(fn (User $user) => [
            $user->id => EventUser::factory()->create([
                'user_id' => $user->id,
                'event_id' => $event->id,
            ]),
        ]);

        foreach ($fursuits as $fursuit) {
            $catchers = $users->except([$fursuit->user->id])->random(fake()->numberBetween(0, 40));

            // This is very slow, but eh it works c:
            foreach ($catchers as $catcher) {
                UserCatch::create([
                    'event_user_id' => $eventUsers[$catcher->id]->id,
                    'fursuit_id' => $fursuit->id,
                ]);
            }
        }
    }
}
