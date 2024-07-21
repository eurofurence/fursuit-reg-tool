<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use Carbon\Carbon;
use App\Enum\EventStateEnum;

class CreateOrUpdateEventForStateCommand extends Command
{
    protected $signature = 'event:state {state}';

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
        $event = Event::first();

        $fromInput = EventStateEnum::tryFrom($state);

        switch ($fromInput) {
            case EventStateEnum::COUNTDOWN:
                $this->createOrUpdateEvent($event, now()->addDays(11), now()->addDays(20), now()->addDays(21), now()->addDays(30));
                break;
            case EventStateEnum::PREORDER:
                $this->createOrUpdateEvent($event, now()->subDays(1), now()->subDays(10), now()->addDays(11), now()->addDays(20));
                break;
            case EventStateEnum::LATE:
                $this->createOrUpdateEvent($event, now()->subDays(20), now()->subDays(10), now()->subDays(9), now()->addDays(10));
                break;
            case EventStateEnum::CLOSED:
                $this->createOrUpdateEvent($event, now()->subDays(30), now()->subDays(20), now()->subDays(19), now()->subDays(1));
                break;
            default:
                $this->error('Invalid state provided.');
                return;
        }

        $this->info("Event has been created or updated to match the '$state' state.");
    }

    private function createOrUpdateEvent($event, $startsAt, $preorderStartsAt, $preorderEndsAt, $orderEndsAt)
    {
        if ($event) {
            $event->update([
                'starts_at' => $startsAt,
                'preorder_starts_at' => $preorderStartsAt,
                'preorder_ends_at' => $preorderEndsAt,
                'order_ends_at' => $orderEndsAt,
            ]);
        } else {
            Event::create([
                'starts_at' => $startsAt,
                'preorder_starts_at' => $preorderStartsAt,
                'preorder_ends_at' => $preorderEndsAt,
                'order_ends_at' => $orderEndsAt,
            ]);
        }
    }
}
