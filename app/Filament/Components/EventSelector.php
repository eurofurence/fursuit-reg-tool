<?php

namespace App\Filament\Components;

use App\Models\Event;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class EventSelector extends Component
{
    public function render(): View
    {
        $events = Event::orderBy('starts_at', 'desc')->get();
        $selectedEventId = session('filament_selected_event_id');

        return view('filament.components.event-selector', [
            'events' => $events,
            'selectedEventId' => $selectedEventId,
        ]);
    }
}
