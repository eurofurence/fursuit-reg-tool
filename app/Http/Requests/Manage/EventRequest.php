<?php

namespace App\Http\Requests\Manage;

use App\Http\Controllers\Manage\EventController;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create and update for /admin/events.
 *
 * The rules are EventResource's form schema (audit 4.1) with two deliberate departures.
 * `cost` is gone: nothing ever read it, so the form no longer offers it. And
 * `free_badge_deadline` is required rather than nullable, because it is the date the free
 * registration badge is honoured until and an event without one silently honours nothing.
 *
 * Everything else the Filament form marked `->required()` stays required, including
 * `mass_printed_at`, which is also the only defensible reading:
 * `events.mass_printed_at` is NOT NULL, so relaxing the rule here would trade a
 * validation message for a constraint violation.
 *
 * Authorisation happens here rather than in the controller body, because a FormRequest
 * validates before the action runs: gating in the controller would answer an unauthorised
 * write with a 422 about its payload instead of a 403.
 *
 * There is no `state` field. Event state is computed by `Event::state()` from
 * `ends_at`, `order_starts_at` and `order_ends_at`, so the three dates below are the only
 * thing that can change it.
 */
class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            ? Gate::allows('update', $event)
            : Gate::allows('create', Event::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // The Select was never required and never validated its own options, while
            // `badge_class` is resolved to a renderer class downstream. It is checked
            // against the list the form offers.
            'badge_class' => ['nullable', 'string', Rule::in(array_keys(EventController::BADGE_CLASS_OPTIONS))],
            // DatePicker, date only. The `date` rule accepts the Y-m-d the control posts.
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            // DateTimePicker, date and time.
            'order_starts_at' => ['required', 'date'],
            'order_ends_at' => ['required', 'date'],
            // Required: this is the date until which the badge included with registration
            // is honoured as free, and the front page and FAQ both quote it.
            'free_badge_deadline' => ['required', 'date'],
            'mass_printed_at' => ['required', 'date'],
            // Toggle::make()->default(true): present and boolean. `required` accepts
            // false, which is the point of a required toggle.
            'catch_em_all_enabled' => ['required', 'boolean'],
            'catch_em_all_start' => ['nullable', 'date'],
            'catch_em_all_end' => ['nullable', 'date'],
            'archival_notice' => ['nullable', 'string'],
        ];
    }

    /**
     * Filament's labels, so a validation message names the field the way the form does.
     * The five auto-labelled fields are left to Laravel, which produces the same words.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'badge_class' => 'Badge Class',
            'order_starts_at' => 'Order Window Start',
            'order_ends_at' => 'Order Window End',
            'free_badge_deadline' => 'Free Badge Deadline',
            'mass_printed_at' => 'Mass Print Date',
            'catch_em_all_enabled' => 'Catch-Em-All Enabled',
            'catch_em_all_start' => 'Catch-Em-All Start',
            'catch_em_all_end' => 'Catch-Em-All End',
            'archival_notice' => 'Archival Notice',
        ];
    }

    /**
     * The attributes to write.
     *
     * Only the twelve fields the form declares, so this array is the allow-list the
     * controller's `forceFill` writes through. `badge_class` normalises an unpicked
     * option to null rather than the empty string the Select posts, so the table's
     * `Not set` fallback and the model see the same absence.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'badge_class' => $validated['badge_class'] ?? null,
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'order_starts_at' => $validated['order_starts_at'],
            'order_ends_at' => $validated['order_ends_at'],
            'free_badge_deadline' => $validated['free_badge_deadline'],
            'mass_printed_at' => $validated['mass_printed_at'],
            'catch_em_all_enabled' => (bool) $validated['catch_em_all_enabled'],
            'catch_em_all_start' => $validated['catch_em_all_start'] ?? null,
            'catch_em_all_end' => $validated['catch_em_all_end'] ?? null,
            'archival_notice' => $validated['archival_notice'] ?? null,
        ];
    }
}
