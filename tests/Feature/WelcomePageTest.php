<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use function Pest\Laravel\travelTo;

uses(RefreshDatabase::class);

test('Closed when no Event', function () {
    $response = $this->get(route('welcome'));
    $response->assertStatus(200);
    $response->assertInertia(fn(Assert $page) => $page
        ->component('Welcome')
        ->where('showState', \App\Enum\EventStateEnum::CLOSED->value))
    ;
    $response->assertStatus(200);
});

test('Check Different States on Welcome Screen', function () {
    // Create Event
    $event = \App\Models\Event::factory()->create([
        'starts_at' => \Carbon\Carbon::parse('2024-06-01'),
        'ends_at' => \Carbon\Carbon::parse('2024-06-30'),
        'preorder_starts_at' => \Carbon\Carbon::parse('2024-05-01'),
        'preorder_ends_at' => \Carbon\Carbon::parse('2024-05-15'),
        'order_ends_at' => \Carbon\Carbon::parse('2024-06-25'),
    ]);
    $tests = [
      ['2024-04-25' => 'countdown'],
      ['2024-05-02' => 'preorder'],
      ['2024-05-16' => 'late'],
      ['2024-06-26' => 'closed'],   ];
    foreach ($tests as $test) {
        // Time Travel
        travelTo(Carbon\Carbon::parse(array_key_first($test)));
        $response = $this->get(route('welcome'));
        $response->assertStatus(200);
        $response->assertInertia(fn(Assert $page) => $page
            ->component('Welcome')
            ->where('showState', $test[array_key_first($test)])
        );
    }
});
