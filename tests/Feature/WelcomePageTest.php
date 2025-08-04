<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\travelTo;

uses(RefreshDatabase::class);

test('Closed when no Event', function () {
    $response = $this->get(route('welcome'));
    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')
        ->where('showState', \App\Enum\EventStateEnum::CLOSED->value));
    $response->assertStatus(200);
});

test('Check Different States on Welcome Screen', function () {
    // Create Event
    $event = \App\Models\Event::factory()->create([
        'starts_at' => \Carbon\Carbon::parse('2024-06-01'),
        'ends_at' => \Carbon\Carbon::parse('2024-06-30'),
        'order_starts_at' => \Carbon\Carbon::parse('2024-06-01'),
        'order_ends_at' => \Carbon\Carbon::parse('2024-06-25'),
    ]);
    $tests = [
        ['2024-05-25' => 'closed'], // Before event starts
        ['2024-06-15' => 'open'],   // During event
        ['2024-07-15' => 'closed'], // After event ends
    ];
    foreach ($tests as $test) {
        // Time Travel
        travelTo(Carbon\Carbon::parse(array_key_first($test)));
        $response = $this->get(route('welcome'));
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('showState', $test[array_key_first($test)])
        );
    }
});
