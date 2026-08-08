<?php

use App\Domain\Printing\Models\Printer;
use App\Enum\PrinterConditionEnum;
use App\Events\PrinterStatusUpdated;
use App\Models\Machine;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * The POS shows one icon per printer and it has to be live.
 *
 * Reporting a condition used to write the columns and stop there, so every
 * screen kept whatever state it had when the page loaded: a jam mid-session
 * looked exactly like a healthy printer.
 */
function agentFor(Printer $printer): Machine
{
    $machine = $printer->machine ?? Machine::factory()->create();
    $printer->forceFill(['machine_id' => $machine->id])->save();
    Sanctum::actingAs($machine, ['*'], 'sanctum');

    return $machine;
}

function reportCondition(Printer $printer, string $condition): TestResponse
{
    return test()->postJson('/api/print-agent/printers/condition', [
        'printer_name' => $printer->name,
        'condition' => $condition,
    ]);
}

it('broadcasts to the POS when the agent reports a condition', function () {
    Event::fake([PrinterStatusUpdated::class]);
    $printer = Printer::factory()->badge()->create();
    agentFor($printer);

    reportCondition($printer, 'card_jam')->assertOk();

    Event::assertDispatched(PrinterStatusUpdated::class,
        fn (PrinterStatusUpdated $e) => $e->printerName === $printer->name
            && $e->status === 'card_jam');
});

it('colours a stopped printer red', function () {
    // The bug this locks out: routing conditions through PrinterStatusEnum
    // mapped jam, media-empty and cover-open to 'warning', which the POS
    // renders green. A jammed printer must never look ready.
    expect(PrinterConditionEnum::CardJam->severity())->toBe('danger')
        ->and(PrinterConditionEnum::RibbonOut->severity())->toBe('danger')
        ->and(PrinterConditionEnum::CoverOpen->severity())->toBe('danger')
        ->and(PrinterConditionEnum::Offline->severity())->toBe('danger')
        ->and(PrinterConditionEnum::Unknown->severity())->toBe('danger');
});

it('colours a working printer blue', function () {
    expect(PrinterConditionEnum::Printing->severity())->toBe('info')
        ->and(PrinterConditionEnum::Initializing->severity())->toBe('info');
});

it('colours a ready printer green, including the low warnings', function () {
    // Low ribbon still prints. Amber would be nicer but the requirement is
    // three colours, and "can still print" is the question the icon answers.
    expect(PrinterConditionEnum::Ok->severity())->toBe('success')
        ->and(PrinterConditionEnum::RibbonLow->severity())->toBe('success')
        ->and(PrinterConditionEnum::CardsLow->severity())->toBe('success');
});

it('carries the label and remedy the POS shows on hover', function () {
    $event = PrinterStatusUpdated::fromCondition('ZXP9', 'badge', PrinterConditionEnum::CardJam);
    $payload = $event->broadcastWith();

    expect($payload['status_severity'])->toBe('danger')
        ->and($payload['status_label'])->toBe('Card jam')
        ->and($payload['error_message'])->toContain('clear the jammed card');
});

it('broadcasts on the channel the POS already listens to', function () {
    $event = PrinterStatusUpdated::fromCondition('ZXP9', 'badge', PrinterConditionEnum::Ok);

    expect($event->broadcastOn()[0]->name)->toBe('pos-printers')
        ->and($event->broadcastAs())->toBe('printer.status.updated');
});
