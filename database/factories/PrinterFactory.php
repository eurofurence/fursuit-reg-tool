<?php

namespace Database\Factories;

use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobTypeEnum;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Factories\Factory;

class PrinterFactory extends Factory
{
    protected $model = Printer::class;

    public function definition(): array
    {
        $paperSizes = [
            [
                'name' => 'A4',
                'width' => 210,
                'height' => 297,
                'mm' => [210, 297]
            ],
            [
                'name' => 'Letter',
                'width' => 216,
                'height' => 279,
                'mm' => [216, 279]
            ]
        ];

        return [
            'machine_id' => Machine::factory(),
            'name' => $this->faker->company . ' Printer',
            'type' => $this->faker->randomElement(PrintJobTypeEnum::cases()),
            'paper_sizes' => $paperSizes,
            'default_paper_size' => 'A4',
            'is_active' => true,
        ];
    }

    public function badge(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => PrintJobTypeEnum::Badge,
        ]);
    }

    public function receipt(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => PrintJobTypeEnum::Receipt,
            'paper_sizes' => [
                [
                    'name' => '80mm',
                    'width' => 80,
                    'height' => 200,
                    'mm' => [80, 200]
                ]
            ],
            'default_paper_size' => '80mm',
        ]);
    }
}