<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'starts_at' => Carbon::now(),
            'ends_at' => Carbon::now(),
            'preorder_ends_at' => Carbon::now(),
            'order_ends_at' => Carbon::now(),
        ];
    }
}
