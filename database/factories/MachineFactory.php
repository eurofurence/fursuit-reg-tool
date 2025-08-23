<?php

namespace Database\Factories;

use App\Enum\QzConnectionStatusEnum;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Factories\Factory;

class MachineFactory extends Factory
{
    protected $model = Machine::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company.' POS Terminal',
            'should_discover_printers' => $this->faker->boolean(70),
            'is_print_server' => $this->faker->boolean(50),
            'qz_connection_status' => $this->faker->randomElement(QzConnectionStatusEnum::cases()),
            'qz_last_seen_at' => $this->faker->optional(0.8)->dateTimeBetween('-1 hour', 'now'),
            'pending_print_jobs_count' => $this->faker->numberBetween(0, 10),
        ];
    }

    public function printServer(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_print_server' => true,
            'should_discover_printers' => true,
        ]);
    }

    public function qzConnected(): self
    {
        return $this->state(fn (array $attributes) => [
            'qz_connection_status' => QzConnectionStatusEnum::Connected,
            'qz_last_seen_at' => now(),
        ]);
    }

    public function qzDisconnected(): self
    {
        return $this->state(fn (array $attributes) => [
            'qz_connection_status' => QzConnectionStatusEnum::Disconnected,
            'qz_last_seen_at' => null,
        ]);
    }
}
