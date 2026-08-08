<?php

namespace App\Http\Requests\Manage;

use App\Http\Controllers\Manage\EventController;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create and update for /admin/settings/events.
 *
 * The rules are EventResource's form schema (audit 4.1) with four deliberate departures.
 * `cost` is gone: nothing ever read it, so the form no longer offers it. And
 * `free_badge_deadline` is required rather than nullable, because it is the date the free
 * registration badge is honoured until and an event without one silently honours nothing.
 * `badge_price` is new, and is not the old `cost` under another name: this one is the fee
 * charged for every badge beyond the included one, it was a constant in
 * BadgeCalculationService until it moved here, and the Welcome page and the FAQ quote it.
 *
 * `mass_printed_at` is the fourth: it is nullable now, and its own helper text always said
 * "if applicable". An empty value means the pre-print run is still ahead, which is exactly
 * what a future date means, so an event no longer has to invent a timestamp to be saved.
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
            // Euros, because that is what the desk and the public pages talk in; stored as
            // cents by payload() below. Required, and 0 is allowed: an event that gives
            // every badge away is a decision someone can make, but it has to be made
            // rather than fallen into by leaving the field blank.
            'badge_price' => ['required', 'numeric', 'min:0', 'max:1000'],
            // Nullable: empty reads as "the pre-print run is still ahead", the same as a
            // future date. Only a run that has already happened needs a value here, and
            // then the copy quotes it back at the attendee.
            'mass_printed_at' => ['nullable', 'date'],
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
            'badge_price' => 'Badge Price',
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
     * Only the thirteen fields the form declares, so this array is the allow-list the
     * controller's `forceFill` writes through. `badge_class` normalises an unpicked
     * option to null rather than the empty string the Select posts, so the table's
     * `Not set` fallback and the model see the same absence, and `badge_price` is the one
     * field whose key changes on the way through, from euros to `badge_price_cents`.
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
            // Euros in, cents out. Rounded rather than cast, because 5.10 is 509.99...
            // as a float and an int cast would quietly charge a cent less.
            'badge_price_cents' => (int) round(((float) $validated['badge_price']) * 100),
            'mass_printed_at' => $validated['mass_printed_at'] ?? null,
            'catch_em_all_enabled' => (bool) $validated['catch_em_all_enabled'],
            'catch_em_all_start' => $validated['catch_em_all_start'] ?? null,
            'catch_em_all_end' => $validated['catch_em_all_end'] ?? null,
            'archival_notice' => $validated['archival_notice'] ?? null,
        ];
    }
}
