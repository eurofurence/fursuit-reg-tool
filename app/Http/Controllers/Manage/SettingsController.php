<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Support\Manage\EventScope;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * Settings: configuration only, every pane behind one rail entry.
 *
 * The panes are General, Events, On-Site Desk, Review Reasons and Users, each a real URL
 * under /admin/settings so the second vertical menu in the page body highlights from the
 * path rather than from client state. The Tools index keeps Badge Preview, the PDF
 * Generator and DB Service: those run something, they do not configure anything.
 *
 * Only General lives in this controller, and it configures nothing: it is the landing pane
 * /admin/settings renders, and it asks the operator to pick a pane from the submenu. On-Site
 * Desk, Review Reasons, Events and Users have their own controllers: the first two because
 * they are the panes with real fields to save, and the last two because each is a full
 * list-plus-form module (EventController, UserController) that moved in here rather than a
 * settings form that grew.
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
 * That is why General ships without a field of its own. Printing and Badges panes existed
 * here too and were the same emptiness twice over, so they are gone rather than dressed up:
 * printers, jobs, batches and badges are records with their own modules, and a pane that
 * only points at another screen is a rail entry pretending to be a setting.
 *
 * Reading is open to the whole panel (`can:access-manage` on the group). Writing is
 * administrative and belongs behind `manage-admin`, so every pane is handed `canEdit` and
 * renders read-only without it.
 */
class SettingsController extends Controller
{
    public function general(EventScope $scope): Response
    {
        $event = $scope->event();

        return inertia('Manage/Settings/General', [
            'event' => $event?->only(['id', 'name']),
            'canEdit' => Gate::allows('manage-admin'),
        ]);
    }
}
