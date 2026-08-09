<?php

namespace Database\Factories\Badge;

use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Pending;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\Badge\State_Fulfillment\Printed;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Badge\State_Payment\Unpaid;
use App\Models\Fursuit\Fursuit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class BadgeFactory extends Factory
{
    protected $model = Badge::class;

    public function definition(): array
    {
        return [
            'is_free_badge' => $this->faker->boolean(),
            'extra_copy_of' => null,
            'status_fulfillment' => $this->faker->randomElement([
                Pending::$name,
                Printed::$name,
                ReadyForPickup::$name,
                PickedUp::$name,
            ]),
            'status_payment' => $this->faker->randomElement([
                Paid::$name,
                Unpaid::$name,
            ]),
            'dual_side_print' => $this->faker->boolean(),
            'extra_copy' => $this->faker->boolean(),
            'apply_late_fee' => $this->faker->boolean(),
            'subtotal' => $this->faker->randomNumber(),
            'tax_rate' => 19,
            'tax' => $this->faker->randomNumber(),
            'total' => $this->faker->randomNumber(),
            'printed_at' => Carbon::now(),
            'pickup_location' => $this->faker->word(),
            'ready_for_pickup_at' => Carbon::now(),
            'picked_up_at' => Carbon::now(),
            'fursuit_id' => Fursuit::factory(),
        ];
    }

    /**
     * A badge whose artwork has already been rendered and still matches the
     * order behind it.
     *
     * PrintBatch::build() refuses badges whose print file is missing or stale,
     * so anything exercising batching needs a badge that looks like it came out
     * of a `badges:generate-print-files` pass.
     */
    public function withPrintFile(): self
    {
        return $this->afterCreating(function (Badge $badge) {
            $fresh = $badge->fresh(['fursuit.species', 'fursuit.event']);

            $badge->forceFill([
                'print_file_path' => 'badges/'.$badge->id.'.pdf',
                'print_file_hash' => GenerateBadgePrintFileJob::inputHash($fresh),
                'print_file_renderer' => class_basename(GenerateBadgePrintFileJob::rendererFor($fresh)),
                'print_file_generated_at' => now(),
            ])->saveQuietly();
        });
    }
}
