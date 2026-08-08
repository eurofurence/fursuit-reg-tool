<?php

namespace Database\Factories\Fursuit;

use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use App\Models\Species;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FursuitFactory extends Factory
{
    protected $model = Fursuit::class;

    /**
     * Approved by default, and deliberately not random any more.
     *
     * A random status was a landmine once a Code of Conduct rejection started stopping the
     * card (BadgePrintQueue::withoutUnapprovedFursuits): every test that printed a badge
     * became a coin flip on whether its fursuit had been cleared. Approved is also what
     * production data looks like by the time a badge is printed, which is what most fixtures
     * are describing. The three states below say so explicitly where it matters.
     */
    public function definition(): array
    {
        return [
            'status' => Approved::$name,
            'name' => $this->faker->name(),
            'image' => $this->faker->filePath(),
            'published' => $this->faker->boolean(),
            'catch_em_all' => $this->faker->boolean(),
            'user_id' => User::factory(),
            'species_id' => Species::factory(),
            'event_id' => Event::factory(),
        ];
    }

    /** Waiting for a verdict: nothing prints and nothing is handed out. */
    public function pending(): self
    {
        return $this->state(fn () => ['status' => Pending::$name]);
    }

    public function approved(): self
    {
        return $this->state(fn () => ['status' => Approved::$name]);
    }

    /** Refused on the Code of Conduct: the blocking outcome. */
    public function rejected(): self
    {
        return $this->state(fn () => ['status' => Rejected::$name]);
    }

    /**
     * Approved, but barred from the gallery and Catch-Em-All.
     *
     * The switches go off with the block, as FursuitReviewService does it: `catch_em_all` is
     * read by the badge artwork and the catch-code lookup, so a blocked fursuit that still
     * carries it would print a QR that resolves to nothing.
     */
    public function publicationBlocked(string $reason = 'Not a photo of a costume.'): self
    {
        return $this->state(fn () => [
            'status' => Approved::$name,
            'publication_blocked_at' => now(),
            'publication_block_reason' => $reason,
            'published' => false,
            'catch_em_all' => false,
        ]);
    }
}
