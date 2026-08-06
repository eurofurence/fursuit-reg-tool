<?php

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\CheckoutItem;
use App\Domain\Checkout\Models\Checkout\Transitions\ToFinished;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Event;
use App\Models\Machine;
use App\Models\Species;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * ToFinished used to credit the user's wallet on every call while guarding only the
 * badge transition, so a re-entrant finish (retry, double submit, requeued job) paid
 * the user repeatedly. One prod checkout fired seven deposits of 1100 and left the
 * user 6600 in credit. Badge state is now the only record, and it is idempotent by
 * construction — this locks that in. See docs/wallet-removal-plan.md.
 */

beforeEach(function () {
    Queue::fake();

    $this->event = Event::factory()->create([
        'order_starts_at' => now()->subDays(2),
        'order_ends_at' => now()->addDays(20),
        'ends_at' => now()->addDays(20),
    ]);
    $this->cashier = Staff::factory()->create();
    $this->machine = Machine::factory()->create();
    $this->customer = User::factory()->create();

    $fursuit = $this->customer->fursuits()->create([
        'event_id' => $this->event->id,
        'species_id' => Species::firstOrCreate(['name' => 'Wolf'], ['name' => 'Wolf', 'checked' => false])->id,
        'name' => 'Repeated',
        'image' => 'fursuits/repeated.jpg',
        'status' => 'approved',
        'published' => false,
        'catch_em_all' => false,
    ]);

    $this->badge = $fursuit->badges()->create([
        'status_fulfillment' => 'pending',
        'status_payment' => 'unpaid',
        'subtotal' => 252,
        'tax_rate' => 0.19,
        'tax' => 48,
        'total' => 300,
        'is_free_badge' => false,
        'dual_side_print' => false,
        'apply_late_fee' => false,
    ]);

    $this->checkout = Checkout::create([
        'status' => 'ACTIVE',
        'payment_method' => 'cash',
        'user_id' => $this->customer->id,
        'cashier_id' => $this->cashier->id,
        'machine_id' => $this->machine->id,
        'subtotal' => 252,
        'tax' => 48,
        'total' => 300,
        'fiskaly_data' => [],
    ]);

    CheckoutItem::create([
        'checkout_id' => $this->checkout->id,
        'payable_type' => $this->badge->getMorphClass(),
        'payable_id' => $this->badge->id,
        'name' => 'Fursuit Badge',
        'description' => [],
        'subtotal' => 252,
        'tax' => 48,
        'total' => 300,
    ]);
});

test('finishing a checkout marks the badge paid and clears the amount due', function () {
    expect($this->customer->amountDue())->toBe(300);

    (new ToFinished($this->checkout))->handle();

    expect($this->badge->fresh()->status_payment->equals(Paid::class))->toBeTrue()
        ->and($this->customer->amountDue())->toBe(0);
});

test('finishing the same checkout twice does not change what the user owes', function () {
    (new ToFinished($this->checkout))->handle();
    $afterFirst = $this->customer->amountDue();

    (new ToFinished($this->checkout->fresh()))->handle();

    expect($this->customer->amountDue())->toBe($afterFirst)
        ->and($this->customer->amountDue())->toBe(0);
});

test('finishing seven times still owes nothing and never goes into credit', function () {
    foreach (range(1, 7) as $ignored) {
        (new ToFinished($this->checkout->fresh()))->handle();
    }

    // The prod failure produced a positive balance (credit). amountDue() cannot go
    // below zero, because it sums unpaid badge totals rather than tracking payments.
    expect($this->customer->amountDue())->toBe(0)
        ->and($this->badge->fresh()->status_payment->equals(Paid::class))->toBeTrue();
});
