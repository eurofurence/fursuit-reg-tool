<?php

namespace Database\Factories;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaffFactory extends Factory
{
    protected $model = Staff::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'pin_code' => fake()->numerify('####'),
            'is_active' => true,
            'last_login_at' => null,
        ];
    }
}