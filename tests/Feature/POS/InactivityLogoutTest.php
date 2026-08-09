<?php

namespace Tests\Feature\POS;

use App\Models\Event;
use App\Models\Machine;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A desk that switched auto logout off has to stay logged in. The timeout is a
 * per-machine setting, so the server side may not fall back to its own fixed
 * five minutes.
 */
class InactivityLogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'order_starts_at' => now()->subDays(10),
            'order_ends_at' => now()->subDays(5),
        ]);
    }

    private function dashboardAs(Machine $machine, ?int $lastActivityAgo = null): TestResponse
    {
        $staff = Staff::factory()->create();

        $test = $this->actingAs($machine, 'machine')
            ->actingAs($staff, 'machine-user');

        if ($lastActivityAgo !== null) {
            $test = $test->withSession(['lastActivityTime' => time() - $lastActivityAgo]);
        }

        return $test->get(route('pos.dashboard'));
    }

    /** @test */
    public function it_keeps_the_user_logged_in_when_auto_logout_is_off()
    {
        $machine = Machine::factory()->create(['auto_logout_timeout' => null]);

        $this->dashboardAs($machine, 60 * 60)->assertOk();
    }

    /** @test */
    public function it_logs_out_after_the_configured_timeout()
    {
        $machine = Machine::factory()->create(['auto_logout_timeout' => 300]);

        $this->dashboardAs($machine, 301)
            ->assertRedirect(route('pos.auth.user.select'));
    }

    /** @test */
    public function it_keeps_the_user_logged_in_within_the_configured_timeout()
    {
        $machine = Machine::factory()->create(['auto_logout_timeout' => 1800]);

        $this->dashboardAs($machine, 600)->assertOk();
    }
}
