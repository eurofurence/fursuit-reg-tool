<?php

namespace Database\Factories;

use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Badge\Badge;
use Illuminate\Database\Eloquent\Factories\Factory;

class PrintJobFactory extends Factory
{
    protected $model = PrintJob::class;

    public function definition(): array
    {
        return [
            'printer_id' => Printer::factory(),
            'printable_type' => Badge::class,
            'printable_id' => Badge::factory(),
            'type' => $this->faker->randomElement(PrintJobTypeEnum::cases()),
            'status' => PrintJobStatusEnum::Pending,
            'file' => 'badges/'.$this->faker->uuid.'.pdf',
            'priority' => $this->faker->numberBetween(1, 10),
            'retry_count' => 0,
        ];
    }

    public function queued(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatusEnum::Queued,
            'queued_at' => now(),
        ]);
    }

    public function printing(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatusEnum::Printing,
            'queued_at' => now()->subMinutes(2),
            'started_at' => now(),
        ]);
    }

    public function printed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatusEnum::Printed,
            'queued_at' => now()->subMinutes(5),
            'started_at' => now()->subMinutes(3),
            'printed_at' => now(),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatusEnum::Failed,
            'failed_at' => now(),
            'error_message' => $this->faker->randomElement([
                'Printer offline',
                'Out of paper',
                'Paper jam',
                'Connection timeout',
            ]),
        ]);
    }

    public function retrying(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatusEnum::Retrying,
            'retry_count' => $this->faker->numberBetween(1, 2),
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatusEnum::Cancelled,
        ]);
    }
}
