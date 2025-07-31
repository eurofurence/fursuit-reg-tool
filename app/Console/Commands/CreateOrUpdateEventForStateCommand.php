<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use Carbon\Carbon;
use App\Enum\EventStateEnum;

class CreateOrUpdateEventForStateCommand extends Command
{
    protected $signature = 'event:state {state?}';

    protected $description = 'Creates or updates an event to match a specific state. Only for local or testing environments.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error('This command can only be run in local or testing environments.');
            return;
        }

        $state = $this->argument('state');
        if (!$state) {
            // Interactive select mode
            $choices = array_map(fn($case) => $case->value, EventStateEnum::cases());
            $state = $this->choice('Select the event state', $choices);
        }

        $event = Event::first();
        $fromInput = EventStateEnum::tryFrom($state);

        switch ($fromInput) {
            case EventStateEnum::OPEN:
                $this->createOrUpdateEvent($event, now()->subDays(1), now()->addDays(30), now()->subDays(1), now()->addDays(25));
                break;
            case EventStateEnum::CLOSED:
                $this->createOrUpdateEvent($event, now()->subDays(30), now()->subDays(1), now()->subDays(25), now()->subDays(15));
                break;
            default:
                $this->error('Invalid state provided.');
                return;
        }

        $this->info("Event has been created or updated to match the '$state' state.");
    }

    private function createOrUpdateEvent($event, $startsAt, $endsAt, $orderStartsAt, $orderEndsAt)
    {
        if ($event) {
            $event->update([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'order_starts_at' => $orderStartsAt,
                'order_ends_at' => $orderEndsAt,
            ]);
        } else {
            Event::create([
                'name' => 'Test Event',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'order_starts_at' => $orderStartsAt,
                'order_ends_at' => $orderEndsAt,
            ]);
        }
    }
}
