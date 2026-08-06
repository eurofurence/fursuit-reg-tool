<?php

namespace Database\Factories;

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

    /**
     * A machine whose print agent has checked in just now.
     */
    public function agentConnected(): self
    {
        return $this->state(fn (array $attributes) => [
            'agent_last_seen_at' => now(),
            'agent_version' => '1.0.0',
        ]);
    }

    /**
     * A machine we have not heard from, either because the agent is not running
     * or because the station lost the network.
     */
    public function agentSilent(): self
    {
        return $this->state(fn (array $attributes) => [
            'agent_last_seen_at' => null,
        ]);
    }
}
