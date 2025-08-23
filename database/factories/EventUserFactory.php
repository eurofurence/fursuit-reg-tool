<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventUser>
 */
class EventUserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = EventUser::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'attendee_id' => fake()->unique()->regexify('[A-Z]{3}[0-9]{3}'),
            'valid_registration' => fake()->boolean(80), // 80% chance of true
            'prepaid_badges' => fake()->numberBetween(0, 3),
        ];
    }
}
