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
            'is_manager' => false,
            'last_login_at' => null,
        ];
    }

    /**
     * A member who can override a badge price at the POS, and approve one for
     * another cashier with their PIN or RFID tag.
     */
    public function manager(): self
    {
        return $this->state(fn () => ['is_manager' => true]);
    }
}
