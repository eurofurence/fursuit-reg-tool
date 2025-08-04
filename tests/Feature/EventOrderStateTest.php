<?php

namespace Tests\Feature;

use App\Enum\EventStateEnum;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventOrderStateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that event allows orders when in the order window but before event starts.
     */
    public function test_event_allows_orders_before_event_starts_if_in_order_window(): void
    {
        // Create an event that starts in the future but orders are currently open
        $event = Event::create([
            'name' => 'Test Event',
            'starts_at' => now()->addMonth(),  // Event starts in a month
            'ends_at' => now()->addMonth()->addDays(4),  // Event ends 4 days later
            'order_starts_at' => now()->subDay(),  // Orders started yesterday
            'order_ends_at' => now()->addWeeks(2),  // Orders end in 2 weeks
            'mass_printed_at' => now()->addDays(10),
        ]);

        $this->assertEquals(EventStateEnum::OPEN, $event->state);
        $this->assertTrue($event->allowsOrders());
    }

    /**
     * Test that event is closed if orders haven't started yet.
     */
    public function test_event_is_closed_if_orders_not_started(): void
    {
        $event = Event::create([
            'name' => 'Test Event',
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addDays(4),
            'order_starts_at' => now()->addDay(),  // Orders start tomorrow
            'order_ends_at' => now()->addWeeks(2),
            'mass_printed_at' => now()->addDays(10),
        ]);

        $this->assertEquals(EventStateEnum::CLOSED, $event->state);
        $this->assertFalse($event->allowsOrders());
    }

    /**
     * Test that event is closed if order period has ended.
     */
    public function test_event_is_closed_if_order_period_ended(): void
    {
        $event = Event::create([
            'name' => 'Test Event',
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addDays(4),
            'order_starts_at' => now()->subWeeks(2),  // Orders started 2 weeks ago
            'order_ends_at' => now()->subDay(),  // Orders ended yesterday
            'mass_printed_at' => now()->addDays(10),
        ]);

        $this->assertEquals(EventStateEnum::CLOSED, $event->state);
        $this->assertFalse($event->allowsOrders());
    }

    /**
     * Test that event is closed if the event itself has ended.
     */
    public function test_event_is_closed_if_event_ended(): void
    {
        $event = Event::create([
            'name' => 'Test Event',
            'starts_at' => now()->subWeeks(2),
            'ends_at' => now()->subWeek(),  // Event ended last week
            'order_starts_at' => now()->subMonth(),
            'order_ends_at' => now()->subDays(10),
            'mass_printed_at' => now()->subDays(15),
        ]);

        $this->assertEquals(EventStateEnum::CLOSED, $event->state);
        $this->assertFalse($event->allowsOrders());
    }

    /**
     * Test the specific EF29 scenario mentioned in the issue.
     */
    public function test_ef29_order_scenario(): void
    {
        // Simulate EF29 scenario: order_starts_at is 2025-08-02 00:00:00
        // Current date is 2025-08-04, so orders should be open
        Carbon::setTestNow('2025-08-04 10:00:00');

        $event = Event::create([
            'name' => 'EF29',
            'starts_at' => '2025-09-03',  // Event starts in September
            'ends_at' => '2025-09-06',
            'order_starts_at' => '2025-08-02 00:00:00',  // Orders started August 2
            'order_ends_at' => '2025-09-06 19:00:00',
            'mass_printed_at' => '2025-08-10 08:00:00',
        ]);

        $this->assertEquals(EventStateEnum::OPEN, $event->state);
        $this->assertTrue($event->allowsOrders());

        // Cleanup
        Carbon::setTestNow();
    }

    /**
     * Test that event allows orders during the event itself.
     */
    public function test_event_allows_orders_during_event(): void
    {
        $event = Event::create([
            'name' => 'Test Event',
            'starts_at' => now()->subDay(),  // Event started yesterday
            'ends_at' => now()->addDays(3),  // Event ends in 3 days
            'order_starts_at' => now()->subWeeks(2),  // Orders started 2 weeks ago
            'order_ends_at' => now()->addDay(),  // Orders end tomorrow
            'mass_printed_at' => now()->addDays(10),
        ]);

        $this->assertEquals(EventStateEnum::OPEN, $event->state);
        $this->assertTrue($event->allowsOrders());
    }

    /**
     * Test edge case: no order dates specified (null values).
     */
    public function test_event_with_null_order_dates(): void
    {
        $event = Event::create([
            'name' => 'Test Event',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
            'order_starts_at' => null,  // No order start limit
            'order_ends_at' => null,    // No order end limit
            'mass_printed_at' => now()->addDays(10),
        ]);

        $this->assertEquals(EventStateEnum::OPEN, $event->state);
        $this->assertTrue($event->allowsOrders());
    }
}
