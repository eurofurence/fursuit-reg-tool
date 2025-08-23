<?php

namespace App\Filament\Components;

use App\Models\Event;
use Filament\View\Component;
use Illuminate\Contracts\View\View;

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
