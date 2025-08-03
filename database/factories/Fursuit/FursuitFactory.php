<?php

namespace Database\Factories\Fursuit;

use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use App\Models\Species;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FursuitFactory extends Factory
{
    protected $model = Fursuit::class;

    public function definition(): array
    {
        return [
            'status' => $this->faker->randomElement([
                Pending::$name,
                Approved::$name,
                Rejected::$name,
            ]),
            'name' => $this->faker->name(),
            'image' => $this->faker->filePath(),
            'published' => $this->faker->boolean(),
            'catch_em_all' => $this->faker->boolean(),
            'user_id' => User::factory(),
            'species_id' => Species::factory(),
            'event_id' => Event::factory(),
        ];
    }
}
