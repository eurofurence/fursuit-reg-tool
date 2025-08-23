<?php

namespace App\Filament\Traits;

use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;

trait HasEventFilter
{
    public static function getSelectedEventId(): ?int
    {
        return session('filament_selected_event_id');
    }

    public static function getSelectedEvent(): ?Event
    {
        $eventId = static::getSelectedEventId();

        return $eventId ? Event::find($eventId) : null;
    }

    public static function applyEventFilter(Builder $query, ?string $relationship = null): Builder
    {
        $eventId = static::getSelectedEventId();

        if (! $eventId) {
            return $query;
        }

        if ($relationship) {
            return $query->whereHas($relationship, function ($q) use ($eventId) {
                $q->where('event_id', $eventId);
            });
        }

        return $query->where('event_id', $eventId);
    }
}
