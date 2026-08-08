<?php

namespace App\Support\Manage;

use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;

/**
 * The global event filter for /manage, and the one reader of the selection.
 *
 * Successor to App\Filament\Middleware\-adjacent FilamentEventSelector plus
 * App\Filament\Traits\HasEventFilter. Two session keys instead of one:
 *
 *   manage.event_id      int|null. null means "all events".
 *   manage.event_chosen  bool. true once the operator has made an explicit choice.
 *
 * The second key is the whole bug fix. FilamentEventSelector forgot the id when the
 * request asked for "all events" and then, in the same handle() call, re-seeded it with
 * the newest event because the key was missing. Forgetting the id and having chosen all
 * events were the same state, so "all events" could never survive a single request and
 * every downstream "no event selected" branch was dead code. Here the default is seeded
 * only while no choice has been recorded, so a null id is a decision the middleware
 * leaves alone.
 *
 * The selection is written by POST /admin/event with a validated event_id, never as a
 * side effect of a query string on an arbitrary GET, so an unknown id is a validation
 * error rather than a poisoned session.
 */
final class EventScope
{
    public const SESSION_ID = 'manage.event_id';

    public const SESSION_CHOSEN = 'manage.event_chosen';

    private bool $resolved = false;

    private ?Event $event = null;

    /**
     * Seed the default selection, but only while the operator has not chosen.
     *
     * Re-running this on every request is deliberate: a newly created event becomes the
     * default for anyone who has never touched the selector.
     */
    public function seedDefault(): void
    {
        if (session()->has(self::SESSION_CHOSEN)) {
            return;
        }

        session()->put(self::SESSION_ID, Event::orderByDesc('starts_at')->value('id'));

        $this->resolved = false;
    }

    /**
     * Record an explicit choice. A null id means all events.
     */
    public function select(?int $id): void
    {
        session()->put(self::SESSION_ID, $id);
        session()->put(self::SESSION_CHOSEN, true);

        $this->resolved = false;
    }

    public function id(): ?int
    {
        return $this->resolve()?->id;
    }

    public function event(): ?Event
    {
        return $this->resolve();
    }

    /**
     * The successor to HasEventFilter::applyEventFilter().
     *
     * Its "no id" branch is now reachable and means what it says: an unscoped query,
     * because the operator asked for all events.
     */
    public function apply(Builder $query, ?string $relationship = null): Builder
    {
        $id = $this->id();

        if ($id === null) {
            return $query;
        }

        if ($relationship !== null) {
            return $query->whereHas($relationship, fn (Builder $q) => $q->where('event_id', $id));
        }

        return $query->where('event_id', $id);
    }

    /**
     * The shared Inertia prop the selector renders from.
     *
     * Every option carries its own orders_open flag, not only the selected one, which is
     * what the blade select could not do.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $event = $this->event();

        return [
            'id' => $event?->id,
            'name' => $event?->name,
            'year' => $event?->starts_at?->format('Y'),
            'orders_open' => (bool) $event?->allowsOrders(),
            'options' => $this->options(),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, year: string|null, orders_open: bool}>
     */
    public function options(): array
    {
        return Event::orderByDesc('starts_at')
            ->get()
            ->map(fn (Event $event) => [
                'id' => $event->id,
                'name' => $event->name,
                'year' => $event->starts_at?->format('Y'),
                'orders_open' => $event->allowsOrders(),
            ])
            ->all();
    }

    /**
     * A stored id whose event has since been deleted resolves to "all events" rather
     * than to a filter nothing can match. The raw session value is never trusted as an
     * int: FilamentEventSelector stored whatever the query string carried and its
     * ?int return type would have thrown on a non-numeric value.
     */
    private function resolve(): ?Event
    {
        if ($this->resolved) {
            return $this->event;
        }

        $this->resolved = true;

        $raw = session(self::SESSION_ID);

        $this->event = is_numeric($raw) ? Event::find((int) $raw) : null;

        return $this->event;
    }
}
