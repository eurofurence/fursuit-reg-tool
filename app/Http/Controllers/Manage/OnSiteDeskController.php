<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\DeskOpeningHours;
use App\Support\Manage\EventScope;
use App\Support\Manage\Toast;
use App\Support\PickupBooths;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Settings > On-Site Desk: everything the badge desk publishes about itself.
 *
 * Two settings, one screen, because they answer the same attendee question - "when do I
 * go, and which queue is mine". Both live on the event row (`events.desk_opening_hours`,
 * `events.pickup_booths`) and are read straight back by the public pickup page and the
 * attendee badge pages, so retuning either between two convention days is an edit rather
 * than a deploy. There is no cache in front of either: the pages read the event row they
 * already load.
 *
 * This is the successor to the Tools > Pickup Booths page. The screen moved into Settings
 * because it configures the convention rather than running anything, which is the line
 * Tools and Settings are split on; the storage did not move, so nothing about the saved
 * value changed with it.
 *
 * The two editors are deliberately not the same shape:
 *
 *  - Opening hours are a row builder. Desk staff type these, the values are two times and
 *    a label, and a JSON typo that blanks the public hours is worse than a slightly longer
 *    form.
 *  - Booth ranges are a row builder too, with a typed input per bound. The value is small
 *    enough to have been a JSON textarea, but the cost of a typo is not: a wrong or
 *    silently-dropped range sends attendees to a desk that will not serve them, and JSON is
 *    a shape an operator can get wrong six ways before any rule looks at a number.
 *
 * Booth validation therefore rejects rather than normalizes. `PickupBooths::normalize()`
 * drops a booth it cannot read, which is right when rendering whatever is already in the
 * column and exactly wrong on save: one mistyped bound would store a split with one booth
 * missing and nobody would see it until the queue formed. Everything normalize() would drop
 * is a field-level error here, on the input that carries the fault, plus the two cross-row
 * faults it cannot see - overlaps and gaps - because both misroute attendees.
 *
 * Event-scoped through EventScope, like every other module page: the counts and the
 * values being edited all belong to the event selected in the header.
 *
 * Reading is open to the panel (`can:access-manage`); writing is admin only, because these
 * ranges are printed on the booth signs and a wrong split - or a wrong opening time -
 * sends attendees into the wrong queue, or an empty hall, on the busiest day of the
 * convention. Both writes are guarded twice, `can:manage-admin` on the route group and
 * `Gate::authorize('manage-admin')` in the method, the same belt-and-braces pattern
 * DB Service uses: the middleware is what a route audit can see, the in-method gate is
 * what stays attached if these routes are ever regrouped.
 */
class OnSiteDeskController extends Controller
{
    public function index(EventScope $scope): Response
    {
        $event = $scope->event();
        $booths = PickupBooths::forEvent($event);

        return inertia('Manage/Settings/OnSiteDesk', [
            // The dates ride along because the hours editor is a list of calendar days:
            // they bound its date picker and a new row defaults to the next convention
            // day rather than to today, which is almost never the day being configured.
            'event' => $event ? [
                'id' => $event->id,
                'name' => $event->name,
                'startsAt' => $event->starts_at?->toDateString(),
                'endsAt' => $event->ends_at?->toDateString(),
            ] : null,
            'canEdit' => Gate::allows('manage-admin'),
            'openingHours' => DeskOpeningHours::forEvent($event),
            'isConfigured' => is_array($event?->pickup_booths) && $event->pickup_booths !== [],
            // Rows, not a JSON string: the editor binds one input per bound, so the shape
            // that leaves here is the shape that comes back.
            'booths' => $this->editorRows($booths),
            'defaults' => $this->editorRows(PickupBooths::DEFAULTS),
            'counts' => PickupBooths::counts($event, $booths),
            'maxHourRows' => DeskOpeningHours::MAX_ROWS,
        ]);
    }

    /**
     * Save the desk's opening hours.
     *
     * The whole list is replaced, not merged, because the form posts every row it shows:
     * a merge would make deleting the last row impossible. An empty list is a legitimate
     * save - it means "we publish no hours" - and is stored as null so the column reads
     * the same as an event that never had any.
     */
    public function updateHours(Request $request, EventScope $scope): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $event = $scope->event();

        if (! $event instanceof Event) {
            return $this->noEvent('editing its opening hours');
        }

        // The desk cannot be open on a day the convention is not running, so the event's
        // own dates bound every row. The editor puts the same bounds on the date input,
        // but a date input's `min`/`max` only marks the field invalid: the value still
        // posts, so the rule has to exist here too.
        $firstDay = $event->starts_at?->toDateString();
        $lastDay = $event->ends_at?->toDateString();

        $dateRules = ['required', 'date_format:Y-m-d', 'distinct'];

        if ($firstDay !== null) {
            $dateRules[] = 'after_or_equal:'.$firstDay;
        }

        if ($lastDay !== null) {
            $dateRules[] = 'before_or_equal:'.$lastDay;
        }

        $withinEvent = $firstDay !== null && $lastDay !== null
            ? 'The desk can only open between '.$firstDay.' and '.$lastDay.', the dates of '.$event->name.'.'
            : 'That day is outside '.$event->name.'.';

        $validated = $request->validate([
            'hours' => ['present', 'array', 'max:'.DeskOpeningHours::MAX_ROWS],
            // `date_format` rather than `date`: only a real calendar day in the one shape
            // the column stores, so "31 September" is refused rather than rolled forward.
            'hours.*.date' => $dateRules,
            'hours.*.opens' => ['required', 'date_format:H:i'],
            'hours.*.closes' => ['required', 'date_format:H:i'],
            'hours.*.note' => ['nullable', 'string', 'max:120'],
            'hours.*.reminds_at' => ['nullable', 'date_format:H:i'],
        ], [
            'hours.*.date.required' => 'Every row needs a date.',
            'hours.*.date.date_format' => 'Pick a real calendar day.',
            'hours.*.date.distinct' => 'This day is already listed. Put both slots in one row, or use the note.',
            'hours.*.date.after_or_equal' => $withinEvent,
            'hours.*.date.before_or_equal' => $withinEvent,
            'hours.*.opens.date_format' => 'Opening times have to look like 10:00.',
            'hours.*.closes.date_format' => 'Closing times have to look like 18:00.',
            'hours.*.reminds_at.date_format' => 'Reminder times have to look like 15:00.',
        ]);

        $hours = DeskOpeningHours::normalize($validated['hours']);

        // The reminder is checked after normalize() rather than in the rules above, because the
        // rules see the rows in the order they were typed and the reminder rules are about the
        // rows in date order: which day is first, and whether the time sits inside that day's
        // own hours. Both are refusals rather than silent drops - a reminder time an operator
        // typed and the panel then ignored is a mail they think is scheduled and is not.
        $this->validateReminders($hours, $validated['hours']);

        $event->desk_opening_hours = $hours === [] ? null : $hours;
        $event->save();

        $reminders = count(array_filter($hours, fn (array $row) => $row['reminds_at'] !== null));

        Toast::flashSuccess(
            'Opening hours saved',
            $hours === []
                ? $event->name.' now publishes no desk hours.'
                : count($hours).' day'.(count($hours) === 1 ? '' : 's').' for '.$event->name.'.'
                    .($reminders === 0
                        ? ' No pickup reminders are scheduled.'
                        : ' '.$reminders.' day'.($reminders === 1 ? '' : 's').' send a pickup reminder.')
        );

        return $this->back();
    }

    /**
     * The reminder times, checked against the day they belong to.
     *
     * Two refusals. The first published day may not carry one at all: that is the day the desk
     * opens, everybody is collecting for the first time, and "you have not picked it up yet" is
     * both untrue and aimed at the longest queue of the convention. And a time outside the day's
     * own hours would send people to a counter that is shut, which is the one thing this mail
     * must never do.
     *
     * Errors are addressed to the row the operator typed, not to the sorted row the fault was
     * found on: normalize() puts the days in date order, and an operator who added Saturday after
     * Sunday would otherwise see the message under the wrong input.
     *
     * @param  list<array{date: string, opens: string, closes: string, note: string|null, reminds_at: string|null}>  $hours
     * @param  array<int, array<string, mixed>>  $submitted
     *
     * @throws ValidationException
     */
    private function validateReminders(array $hours, array $submitted): void
    {
        $slots = [];

        foreach ($submitted as $index => $row) {
            $date = is_array($row) && is_string($row['date'] ?? null) ? trim($row['date']) : null;

            if ($date !== null && ! isset($slots[$date])) {
                $slots[$date] = $index;
            }
        }

        $errors = [];

        foreach ($hours as $index => $row) {
            $slot = $slots[$row['date']] ?? $index;
            if ($row['reminds_at'] === null) {
                continue;
            }

            if ($index === 0) {
                $errors['hours.'.$slot.'.reminds_at'] = 'The first desk day does not send reminders: everybody is collecting for the first time.';

                continue;
            }

            if ($row['reminds_at'] < $row['opens'] || $row['reminds_at'] >= $row['closes']) {
                $errors['hours.'.$slot.'.reminds_at'] = 'Send the reminder while the desk is open, between '.$row['opens'].' and '.$row['closes'].'.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function updateBooths(Request $request, EventScope $scope): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $event = $scope->event();

        if (! $event instanceof Event) {
            return $this->noEvent('editing its booths');
        }

        // normalize() sorts and fills in the derived labels, and by now it has nothing left
        // to drop: validateBooths() has already refused everything it would have discarded.
        $booths = PickupBooths::normalize($this->validateBooths($request));

        $event->pickup_booths = $booths;
        $event->save();

        Toast::flashSuccess(
            'Booths saved',
            count($booths).' booth'.(count($booths) === 1 ? '' : 's').' for '.$event->name.'.'
        );

        return $this->back();
    }

    /**
     * Reset to the built-in split by clearing the column, so the event follows the
     * defaults again rather than pinning a copy of today's defaults.
     */
    public function resetBooths(EventScope $scope): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $event = $scope->event();

        if (! $event instanceof Event) {
            return $this->noEvent('editing its booths');
        }

        $event->pickup_booths = null;
        $event->save();

        Toast::flashSuccess('Booths reset', $event->name.' now uses the default split.');

        return $this->back();
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('admin.settings.on-site-desk');
    }

    private function noEvent(string $action): RedirectResponse
    {
        Toast::flashDanger('No event selected', 'Pick an event in the header before '.$action.'.');

        return $this->back();
    }

    /**
     * The booths as the editor wants them: one row per booth, with the label blanked when
     * it is only the derived one.
     *
     * `PickupBooths::normalize()` fills every label in, so without this the label input
     * would always arrive pre-filled and a range edit would leave a stale caption behind it
     * ("0 – 999" on a booth that now ends at 1499). Blank means "derive it", which is what
     * the placeholder in the editor says and what normalize() does on the way back.
     *
     * @param  list<array{label: string, from: int, to: int|null}>  $booths
     * @return list<array{label: string|null, from: int, to: int|null}>
     */
    private function editorRows(array $booths): array
    {
        return array_map(fn (array $booth) => [
            'label' => $booth['label'] === PickupBooths::label($booth['from'], $booth['to'])
                ? null
                : $booth['label'],
            'from' => $booth['from'],
            'to' => $booth['to'],
        ], $booths);
    }

    /**
     * The submitted booth rows, checked per row and then against each other.
     *
     * Every message is attached to the field that carries the fault, `booths.{index}.from`
     * or `booths.{index}.to`, so the editor prints it under the input the operator has to
     * fix instead of at the top of a form with six identical-looking rows.
     *
     * A row is valid on exactly the terms `PickupBooths::normalize()` reads: a whole-number
     * `from`, and a `to` that is either empty (the open-ended booth) or a whole number not
     * below `from`. The difference is what happens when it is not - normalize() drops the
     * row, this refuses the save.
     *
     * @return list<array{label: string|null, from: int, to: int|null}>
     *
     * @throws ValidationException
     */
    private function validateBooths(Request $request): array
    {
        $rows = $request->validate([
            'booths' => ['required', 'array', 'min:1'],
            'booths.*' => ['array'],
            // `integer` takes the numeric strings a number input posts and refuses
            // everything else, which is the non-numeric case normalize() would drop.
            'booths.*.from' => ['required', 'integer', 'min:0'],
            'booths.*.to' => ['nullable', 'integer', 'min:0'],
            'booths.*.label' => ['nullable', 'string', 'max:255'],
        ], [
            'booths.required' => 'Add at least one booth.',
            'booths.array' => 'Add at least one booth.',
            'booths.min' => 'Add at least one booth.',
            'booths.*.from.required' => 'Enter the first attendee id this booth serves.',
            'booths.*.from.integer' => 'The start has to be a whole number.',
            'booths.*.from.min' => 'Attendee ids are not negative.',
            'booths.*.to.integer' => 'The end has to be a whole number, or empty for the open-ended booth.',
            'booths.*.to.min' => 'Attendee ids are not negative.',
        ])['booths'];

        $booths = [];

        foreach ($rows as $index => $row) {
            $from = (int) $row['from'];
            $to = isset($row['to']) && $row['to'] !== '' && $row['to'] !== null ? (int) $row['to'] : null;

            if ($to !== null && $to < $from) {
                throw ValidationException::withMessages([
                    "booths.$index.to" => 'This booth ends before it starts.',
                ]);
            }

            $label = isset($row['label']) && is_string($row['label']) && trim($row['label']) !== ''
                ? trim($row['label'])
                : null;

            // The submitted index rides along so a cross-row message lands on the row the
            // operator typed rather than on its position after sorting.
            $booths[] = ['index' => $index, 'label' => $label, 'from' => $from, 'to' => $to];
        }

        usort($booths, fn (array $a, array $b) => $a['from'] <=> $b['from']);

        $this->assertBoothsTile($booths);

        return array_map(
            fn (array $booth) => ['label' => $booth['label'], 'from' => $booth['from'], 'to' => $booth['to']],
            $booths
        );
    }

    /**
     * The cross-row check: in order, the booths have to tile the attendee ids exactly.
     *
     * An overlap puts one attendee in two queues and a gap puts one in none, and neither is
     * visible in a list of six ranges read one row at a time. Only the last booth may be
     * open-ended, because an open end anywhere else swallows every booth after it - which
     * is the case the old JSON check caught, kept here on the row that causes it.
     *
     * @param  list<array{index: array-key, label: string|null, from: int, to: int|null}>  $booths
     *
     * @throws ValidationException
     */
    private function assertBoothsTile(array $booths): void
    {
        foreach ($booths as $position => $booth) {
            $next = $booths[$position + 1] ?? null;

            if ($next === null) {
                continue;
            }

            if ($booth['to'] === null) {
                throw ValidationException::withMessages([
                    'booths.'.$booth['index'].'.to' => 'Only the last booth may be open-ended, and this one has booths after it.',
                ]);
            }

            if ($next['from'] <= $booth['to']) {
                throw ValidationException::withMessages([
                    'booths.'.$next['index'].'.from' => 'This overlaps the booth ending at '.$booth['to'].'.',
                ]);
            }

            if ($next['from'] > $booth['to'] + 1) {
                $gapStart = $booth['to'] + 1;

                throw ValidationException::withMessages([
                    'booths.'.$next['index'].'.from' => 'Attendee ids '.$gapStart.' to '.($next['from'] - 1).' would have no booth. Start at '.$gapStart.'.',
                ]);
            }
        }
    }
}
