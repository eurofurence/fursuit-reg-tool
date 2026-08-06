<?php

namespace Database\Factories;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrintBatchStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

class PrintBatchFactory extends Factory
{
    protected $model = PrintBatch::class;

    public function definition(): array
    {
        return [
            'name' => 'Batch '.$this->faker->numberBetween(1, 500),
            'printer_id' => Printer::factory()->badge(),
            'status' => PrintBatchStatusEnum::Draft,
        ];
    }

    public function ready(): self
    {
        return $this->state(fn () => ['status' => PrintBatchStatusEnum::Ready]);
    }

    /**
     * The only state an agent may claim jobs from.
     */
    public function printing(): self
    {
        return $this->state(fn () => [
            'status' => PrintBatchStatusEnum::Printing,
            'started_at' => now(),
        ]);
    }

    public function paused(): self
    {
        return $this->state(fn () => [
            'status' => PrintBatchStatusEnum::Paused,
            'started_at' => now()->subMinutes(5),
            'pause_reason' => 'Card jam',
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn () => [
            'status' => PrintBatchStatusEnum::Completed,
            'started_at' => now()->subMinutes(20),
            'completed_at' => now(),
        ]);
    }
}
