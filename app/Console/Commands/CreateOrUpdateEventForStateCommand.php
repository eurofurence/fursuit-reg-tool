<?php

namespace App\Console\Commands;

use App\Enum\EventStateEnum;
use App\Models\Event;
use Illuminate\Console\Command;

class CreateOrUpdateEventForStateCommand extends Command
{
    protected $signature = 'event:state {state?}';

    protected $description = 'Creates or updates an event to match a specific state. States: pre-order, order, event-order, closed. Only for local or testing environments.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command can only be run in local or testing environments.');

            return;
        }

        $state = $this->argument('state');
        if (! $state) {
            // Interactive select mode
            $choices = ['pre-order', 'order', 'event-order', 'closed'];
            $state = $this->choice('Select the event state', $choices);
        }

        $event = Event::getActiveEvent();
        
        if ($event) {
            $this->info("Modifying existing event: {$event->name} (ID: {$event->id})");
        } else {
            $this->info("No existing event found, will create a new one");
        }

        switch ($state) {
            case 'pre-order':
                // Orders haven't started yet, event in future
                $this->createOrUpdateEvent(
                    $event, 
                    now()->addDays(20), // Event starts in 20 days
                    now()->addDays(25), // Event ends in 25 days
                    now()->addDays(2),  // Orders start in 2 days
                    now()->addDays(18)  // Orders end 2 days before event
                );
                $this->info('Event set to PRE-ORDER state: Orders start in 2 days, event in 20 days');
                break;
                
            case 'order':
                // Orders are currently open, event in future
                $this->createOrUpdateEvent(
                    $event,
                    now()->addDays(15), // Event starts in 15 days
                    now()->addDays(20), // Event ends in 20 days
                    now()->subDays(1),  // Orders started yesterday
                    now()->addDays(13)  // Orders end 2 days before event
                );
                $this->info('Event set to ORDER state: Orders are currently open, event in 15 days');
                break;
                
            case 'event-order':
                // Event is currently happening, orders still open
                $this->createOrUpdateEvent(
                    $event,
                    now()->subDays(2),  // Event started 2 days ago
                    now()->addDays(3),  // Event ends in 3 days
                    now()->subDays(30), // Orders started 30 days ago
                    now()->addDays(1)   // Orders end in 1 day
                );
                $this->info('Event set to EVENT-ORDER state: Event is happening now, orders still open');
                break;
                
            case 'closed':
                // Everything is closed/finished
                $this->createOrUpdateEvent(
                    $event,
                    now()->subDays(10), // Event ended 10 days ago
                    now()->subDays(5),  // Event ended 5 days ago
                    now()->subDays(40), // Orders started 40 days ago
                    now()->subDays(12)  // Orders ended 12 days ago
                );
                $this->info('Event set to CLOSED state: Event and orders have ended');
                break;
                
            case 'open': // Legacy support
                $this->createOrUpdateEvent($event, now()->subDays(1), now()->addDays(30), now()->subDays(1), now()->addDays(25));
                $this->info('Event set to legacy OPEN state');
                break;
                
            default:
                $this->error('Invalid state provided. Valid states: pre-order, order, event-order, closed');
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
