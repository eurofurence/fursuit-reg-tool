<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Support\Manage\EventScope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Response;

/**
 * Settings: configuration only, four panes behind one rail entry.
 *
 * The panes are General, On-Site Desk, Printing and Badges, each a real URL under
 * /admin/settings so the second vertical menu in the page body highlights from the path
 * rather than from client state. Tools keeps Badge Preview and the PDF Generator, and
 * Maintenance keeps DB Service: those run something, they do not configure anything.
 *
 * Three of the four panes live in this controller. On-Site Desk has its own
 * (App\Http\Controllers\Manage\OnSiteDeskController) because it is the only pane with real
 * fields to save, and its two writes belong beside the reads that feed them.
 *
 * STORAGE: per-event columns on `events`, and nothing else.
 *
 * This app has no settings table, no settings model and no settings config class, and this
 * page does not add one. Everything the convention configures is already a column on the
 * event row, which is the right grain: EF29 and EF30 are configured independently and an
 * operator switching the header selector expects the numbers to follow. Pickup booths
 * proved the pattern first (`events.pickup_booths`, read straight back by the attendee
 * badge pages), so Settings follows it rather than opening a second store beside it.
 *
 * The event a pane edits is therefore the one EventScope holds, exactly like every other
 * module page. With no event selected a pane still renders; it says so and offers nothing
 * to write, because there is no row to write it to.
 *
 * If a future setting is genuinely app-level rather than per-event - a Fiskaly endpoint, a
 * QZ Tray host, anything that is one value for the whole installation - it does not belong
 * in a new table either. Those are deployment inputs and already live in config/ and .env,
 * where a value cannot drift between two conventions running in the same database.
 *
 * NO FIELD IS EDITABLE IN TWO PLACES.
 *
 * The Events module owns the event record: name, badge_class and the seven date
 * fields, all twelve attributes EventRequest accepts. Settings owns exactly two event
 * columns the Events form has never had, `pickup_booths` and `desk_opening_hours`, and
 * both are edited on the On-Site Desk pane. Nothing moved in either direction, and nothing
 * is duplicated, so the two screens cannot disagree and neither can silently win.
 *
 * That is why the three panes in this controller ship empty. General, Printing and Badges
 * have no field of their own yet, and the honest empty state names the screen that does
 * own the adjacent fields instead of growing a second editor for them. A setting no code
 * reads is worse than no setting; a setting two screens write is worse again.
 *
 * Reading is open to the whole panel (`can:access-manage` on the group). Writing is
 * administrative and belongs behind `manage-admin`, so every pane is handed `canEdit` and
 * renders read-only without it.
 */
class SettingsController extends Controller
{
    public function general(EventScope $scope): Response
    {
        return inertia('Manage/Settings/General', $this->props($scope, [
            'links' => $this->compact([
                $this->eventLink($scope),
                $this->link('All events', 'manage.events.index', [], $this->permits('viewAny', Event::class)),
            ]),
        ]));
    }

    public function printing(EventScope $scope): Response
    {
        return inertia('Manage/Settings/Printing', $this->props($scope, [
            'links' => $this->compact([
                $this->link('Printers', 'manage.printers.index', [], $this->permits('viewAny', Printer::class)),
                $this->link('Print Jobs', 'manage.print-jobs.index', [], $this->permits('viewAny', PrintJob::class)),
                $this->link('Print Batches', 'manage.print-batches.index', [], $this->permits('viewAny', PrintBatch::class)),
            ]),
        ]));
    }

    public function badges(EventScope $scope): Response
    {
        return inertia('Manage/Settings/Badges', $this->props($scope, [
            'links' => $this->compact([
                $this->eventLink($scope),
                $this->link('Badges', 'manage.badges.index', [], $this->permits('viewAny', Badge::class)),
            ]),
        ]));
    }

    /**
     * What every pane gets: the event it configures, and whether this operator may write.
     *
     * Reads only. Nothing in here touches the database beyond the event row EventScope has
     * already resolved for the status strip on the same request.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function props(EventScope $scope, array $extra = []): array
    {
        $event = $scope->event();

        return array_merge([
            'event' => $event?->only(['id', 'name']),
            'canEdit' => Gate::allows('manage-admin'),
        ], $extra);
    }

    /**
     * "Edit this event", the one link every pointer pane wants, pointing at the form that
     * actually owns the fields the pane refuses to duplicate.
     *
     * @return array{label: string, url: string}|null
     */
    private function eventLink(EventScope $scope): ?array
    {
        $event = $scope->event();

        if (! $event instanceof Event) {
            return null;
        }

        return $this->link(
            $event->name.' in Events',
            'manage.events.edit',
            [$event->id],
            Gate::allows('update', $event),
        );
    }

    /**
     * A link is dropped when its route does not exist yet or the operator cannot open it,
     * the same two questions App\Support\Manage\Navigation asks of a rail item, so a pane
     * never points a reviewer at a 403.
     *
     * @param  array<int, mixed>  $parameters
     * @return array{label: string, url: string}|null
     */
    private function link(string $label, string $route, array $parameters = [], bool $visible = true): ?array
    {
        if (! $visible || ! Route::has($route)) {
            return null;
        }

        return ['label' => $label, 'url' => route($route, $parameters)];
    }

    /**
     * @param  array<int, array{label: string, url: string}|null>  $links
     * @return array<int, array{label: string, url: string}>
     */
    private function compact(array $links): array
    {
        return array_values(array_filter($links));
    }

    /**
     * Same rule as Navigation::permits(): a model with no policy registered is treated as
     * visible, so a pointer does not vanish between the phase that adds a module and the
     * phase that adds its policy.
     */
    private function permits(string $ability, string $model): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return Gate::getPolicyFor($model) === null
            || Gate::forUser($user)->allows($ability, $model);
    }
}
