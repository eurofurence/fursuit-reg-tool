<?php

namespace App\Support\Manage;

use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\TseClient;
use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrinterConditionEnum;
use App\Enum\PrintJobStatusEnum;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Pending;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\SumUpReader;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * The rail structure and the top strip's counts, shared on every /manage response.
 *
 * Items whose route does not exist yet are dropped, so each rebuild phase can add a
 * module without touching this file, and items the current user cannot open are dropped
 * too, which is what Filament's policies did to its nav. Badge counts are cached briefly
 * because the status strip polls.
 *
 * Two count changes are deliberate (plan 2.8). The fursuit chip showed the total fursuit
 * count coloured by the pending count, two different numbers behind one chip; it becomes
 * the pending count, which is the number a reviewer acts on. The printer chip is new and
 * counts what PrinterConditionEnum::isStop() says has stopped, which admin has never
 * surfaced at all.
 */
final class Navigation
{
    private const BADGE_TTL = 5;

    /**
     * The event id is passed in rather than read here, so EventScope stays the one reader
     * of the selection. Null means all events, which is now a reachable state.
     */
    public function __construct(private readonly ?int $eventId = null) {}

    /**
     * @return array<int, array{label: string, items: array<int, array<string, mixed>>}>
     */
    public function groups(): array
    {
        $badges = $this->badges();

        /*
         * Six groups, down from the Filament seven. Four of them existed only because a
         * single page needed a heading to sit under - Sales held Checkouts, User Management
         * held Users, Maintenance held DB Service - and a group of one is a heading that
         * says the row's name twice. Each of those moved to the subject it belongs to:
         * Checkouts to POS, Users to Settings, the two tool pages and DB Service to one
         * Tools index.
         */
        $groups = [
            ['label' => 'Overview', 'items' => [
                $this->item('Dashboard', 'layout-dashboard', 'admin.dashboard'),
            ]],
            /*
             * Events is not here. It is edited a handful of times per convention and every
             * field on it configures the convention rather than running it, so it moved into
             * Settings as a pane of its own; see settings() below.
             */
            ['label' => 'Registration', 'items' => [
                $this->item('Badges', 'id-card', 'admin.badges.index', $badges['badges'] ?? null, $this->permits('viewAny', Badge::class)),
                $this->item('Fursuits', 'circle-user', 'admin.fursuits.index', $badges['fursuits'] ?? null, $this->permits('viewAny', Fursuit::class)),
            ]],
            /*
             * Special Codes is the only admin surface the game has, and it is a game record
             * rather than a registration one: the code is what a Catch-Em-All player scans.
             * It sat under Registration because it was filed beside the badge tables it has
             * nothing to do with.
             */
            ['label' => 'Catch-Em-All', 'items' => [
                $this->item('Special Codes', 'qr-code', 'admin.special-codes.index', null, $this->permits('viewAny', SpecialCode::class)),
            ]],
            /*
             * Checkouts is first in POS, not a "Sales" group of its own. Every checkout in
             * the database was rung up on one of the tills below it, so the receipt and the
             * machine, staff member and reader that produced it are one subject.
             */
            ['label' => 'POS', 'items' => [
                $this->item('Checkouts', 'shopping-cart', 'admin.checkouts.index', null, $this->permits('viewAny', Checkout::class)),
                $this->item('Machines', 'monitor', 'admin.machines.index', null, $this->permits('viewAny', Machine::class)),
                $this->item('Staff', 'users-round', 'admin.staff.index', null, $this->permits('viewAny', Staff::class)),
                $this->item('SumUp Readers', 'credit-card', 'admin.sumup-readers.index', null, $this->permits('viewAny', SumUpReader::class)),
                $this->item('TSE Clients', 'shield-check', 'admin.tse-clients.index', null, $this->permits('viewAny', TseClient::class)),
            ]],
            /*
             * Print Jobs has no rail entry. A card is only ever worked on as part of the run
             * it belongs to, and the batch page already lists its cards with the same table,
             * the same actions and the verify control the standalone list never had. The
             * routes stay: `admin.print-jobs.show` and friends are what the batch table links
             * to, and the badge detail still deep-links the queue filtered to one badge.
             */
            ['label' => 'Printing', 'items' => [
                $this->item('Printers', 'printer', 'admin.printers.index', $badges['printers'] ?? null, $this->permits('viewAny', Printer::class)),
                $this->item('Print Batches', 'layers', 'admin.print-batches.index', $badges['batches'] ?? null, $this->permits('viewAny', PrintBatch::class)),
            ]],
            /*
             * Two entries, not nine. Both carry their own list inside the page body -
             * Settings its vertical submenu (SettingsLayout.vue, fed by settings() below),
             * Tools its card grid (fed by tools()) - so the panes and the tool pages are
             * reached from there rather than from the rail; putting them all here would
             * mean the same list twice, two columns apart, with two active markers.
             *
             * `admin.settings.general` is the pane /admin/settings renders, so the rail
             * item and the first pane are one URL.
             *
             * Both carry `manage-admin`, so the whole group is absent for a reviewer. That
             * is not "hiding a rail entry over one child": every pane behind Settings and
             * every card behind Tools is admin-only now (docs/admin/roles.md), so the two
             * entries would open pages with nothing on them. The per-child gates inside
             * settings() and tools() stay, because an admin still sees a filtered list
             * there and those functions are what a later reviewer-visible pane would use.
             */
            ['label' => 'Configuration', 'items' => [
                $this->item('Tools', 'wrench', 'admin.tools.index', null, $this->permits('manage-admin')),
                $this->item('Settings', 'cog', 'admin.settings.general', null, $this->permits('manage-admin')),
            ]],
        ];

        return collect($groups)
            ->map(fn (array $group) => [
                'label' => $group['label'],
                'items' => array_values(array_filter($group['items'])),
            ])
            ->filter(fn (array $group) => $group['items'] !== [])
            ->values()
            ->all();
    }

    /**
     * The Settings submenu: the panes SettingsLayout.vue lists inside the page body.
     *
     * Built here rather than declared in the layout, which is where it started. Every pane
     * used to be open to anyone who could open the panel, so a client-side list was four
     * constants taking a round trip. Events is the pane that ended that: EventPolicy gates
     * it on `is_admin`, so a reviewer who saw the card would be offered a 403. The list is
     * therefore filtered the same way the rail is, by Route::has plus the policy, and a
     * pane the operator cannot open is simply absent.
     *
     * `blurb` is what the card is for. One line at that width: it is a signpost, and the
     * pane itself explains the fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function settings(): array
    {
        $panes = [
            $this->pane('general', 'General', 'sliders-horizontal', 'admin.settings.general',
                'The landing pane: pick what to configure from this menu.'),
            $this->pane('events', 'Events', 'calendar-days', 'admin.settings.events.index',
                'The conventions themselves: dates, order window and badge class.',
                $this->permits('viewAny', Event::class)),
            $this->pane('on-site-desk', 'On-Site Desk', 'map-pin', 'admin.settings.on-site-desk',
                'Opening hours and the booth ranges attendees queue by.'),
            /*
             * No Printing or Badges pane. Both were placeholders that configured nothing and
             * only linked at the Printers, Print Batches and Badges modules, so they are gone
             * rather than kept as a submenu entry that leads to a paragraph.
             */
            $this->pane('review-reasons', 'Review Reasons', 'shield-check', 'admin.settings.review-reasons',
                'The keywords the review queue offers, and the text attendees receive.'),
            /*
             * Users is a pane rather than a rail group of its own. Accounts are created a
             * handful of times per convention and every field on one - reviewer, admin -
             * configures who may work the panel rather than running anything through it,
             * which is the line Settings is drawn on, the same one that moved Events here.
             */
            $this->pane('users', 'Users', 'users', 'admin.settings.users.index',
                'Panel accounts, and which of them review or administer.',
                $this->permits('viewAny', User::class)),
        ];

        return array_values(array_filter($panes));
    }

    /**
     * The Tools index: one card per tool, replacing the Tools and Maintenance rail groups.
     *
     * Same shape as settings() and filtered the same way, by Route::has plus the gate, so a
     * tool the operator cannot open is absent rather than a card that 403s. DB Service is
     * the one entry with a gate: it is the single write in the panel and `manage-admin`
     * guards it on the route, in the controller and here.
     *
     * `danger` marks a card that changes data, so the one repair does not read like the two
     * read-only exports beside it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tools(): array
    {
        $tools = [
            $this->pane('pdf', 'PDF Generator', 'file-text', 'admin.tools.pdf',
                'Badge lists and box labels for the print run, as PDFs to hand out.'),
            $this->pane('badge-preview', 'Badge Preview', 'eye', 'admin.tools.badge-preview',
                'Look a badge up by custom id and see the card it prints as.'),
            $this->pane('db-service', 'DB Service', 'wrench', 'admin.maintenance.db-service',
                'Repair badges charged the fee although prepaid entitlement was left.',
                $this->permits('manage-admin')),
        ];

        return collect($tools)
            ->filter()
            ->map(fn (array $tool) => $tool + ['danger' => $tool['key'] === 'db-service'])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pane(string $key, string $label, string $icon, string $route, string $blurb, bool $visible = true): ?array
    {
        if (! $visible || ! Route::has($route)) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'blurb' => $blurb,
            'url' => route($route),
        ];
    }

    /**
     * The top strip's own segments: the numbers staff act on (plan 1.2).
     *
     * Same counts as the rail, from the same cached read, so the chip beside "Fursuits"
     * and the "pending reviews" segment three elements to its left can never disagree.
     *
     * The printing segment is the work still ahead, not the work already done and
     * unchecked: an operator asks "how many cards are left", and the unverified count
     * answered a question only the print-batch page cares about. Unverified cards are
     * still counted for the rail chip, which is where that question belongs. The two print
     * numbers are a pair, left and done, and they are read together.
     *
     * Segments are rendered at zero as well, tone idle. An operator reading "0 pending"
     * knows the queue is empty; a segment that vanishes only says the strip changed.
     *
     * @return array{segments: array<int, array<string, mixed>>}
     */
    public function strip(): array
    {
        $counts = $this->counts();
        $segments = [];

        if ($this->permits('viewAny', Fursuit::class)) {
            $segments[] = [
                'key' => 'pending_fursuits',
                'label' => 'pending reviews',
                'value' => $counts['fursuits'],
                'tone' => $counts['fursuits'] > 0 ? Status::WARN : Status::IDLE,
                'icon' => 'hourglass',
                // The queue, not the list: the number is the review backlog, so the click
                // hands the reviewer the next record instead of a table they then have to
                // pick a row out of. An empty queue redirects to the list on its own
                // (FursuitReviewController::index), so zero is not a dead end.
                'url' => $this->urlFor('admin.fursuits.review'),
            ];
        }

        if ($this->permits('viewAny', Badge::class)) {
            $segments[] = [
                'key' => 'unprinted_badges',
                'label' => 'left to print',
                'value' => $counts['unprinted'],
                'tone' => $counts['unprinted'] > 0 ? Status::WARN : Status::IDLE,
                'icon' => 'printer',
                'url' => $this->urlFor('admin.badges.index', [
                    'filter' => ['status_fulfillment' => ['pending', 'processing']],
                ]),
            ];

            /*
             * The other half of the same question. "Left to print" alone says how much
             * work is ahead but nothing about how far the run has got, and during the
             * convention that is the number an operator is actually asked for. Tone ok
             * rather than warn: it is progress, not a queue anybody has to clear.
             */
            $segments[] = [
                'key' => 'printed_badges',
                'label' => 'printed',
                'value' => $counts['printed'],
                'tone' => $counts['printed'] > 0 ? Status::OK : Status::IDLE,
                'icon' => 'id-card',
                'url' => $this->urlFor('admin.badges.index', [
                    'filter' => ['status_fulfillment' => ['ready_for_pickup', 'picked_up']],
                ]),
            ];
        }

        return ['segments' => $segments];
    }

    /**
     * @param  array{label: string, tone: string}|null  $badge
     * @return array<string, mixed>|null
     */
    private function item(string $label, string $icon, string $route, ?array $badge = null, bool $visible = true): ?array
    {
        if (! $visible || ! Route::has($route)) {
            return null;
        }

        return [
            'label' => $label,
            'icon' => $icon,
            'route' => $route,
            'url' => route($route),
            'badge' => $badge,
        ];
    }

    /**
     * Null for a route a later phase has not registered yet, which the strip renders as
     * plain text rather than a dead link.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function urlFor(string $route, array $parameters = []): ?string
    {
        return Route::has($route) ? route($route, $parameters) : null;
    }

    /**
     * A model with no policy registered yet is treated as visible, the way Filament
     * treated a resource with no policy, so a rail item does not silently vanish between
     * the phase that adds its routes and the phase that adds its policy. A named gate
     * that is not defined is treated the same way.
     */
    private function permits(string $ability, ?string $model = null): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if ($model !== null) {
            return Gate::getPolicyFor($model) === null
                || Gate::forUser($user)->allows($ability, $model);
        }

        return ! Gate::has($ability) || Gate::forUser($user)->allows($ability);
    }

    /**
     * Mirrors the Filament navigation badges, with the two corrections from plan 2.8.
     *
     * @return array<string, array{label: string, tone: string}|null>
     */
    private function badges(): array
    {
        $counts = $this->counts();

        return [
            'badges' => $counts['badges'] > 0 ? ['label' => (string) $counts['badges'], 'tone' => Status::IDLE] : null,
            'fursuits' => $counts['fursuits'] > 0 ? ['label' => (string) $counts['fursuits'], 'tone' => Status::WARN] : null,
            'printers' => $counts['printers'] > 0 ? ['label' => (string) $counts['printers'], 'tone' => Status::DANGER] : null,
            'batches' => $counts['batches'] > 0 ? ['label' => (string) $counts['batches'], 'tone' => Status::WARN] : null,
        ];
    }

    /**
     * The raw numbers behind both the rail chips and the strip segments.
     *
     * Cached briefly and keyed by the selected event, because the strip polls and the
     * two surfaces ask for them on the same request. Only the two event-owned counts are
     * scoped: printers and print jobs belong to the hall, not to an event, and plan 2.9
     * lists neither as scoped.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $eventId = $this->eventId;

        return Cache::remember('manage.nav.counts.'.($eventId ?? 'all'), self::BADGE_TTL, function () use ($eventId) {
            /*
             * One grouped read behind all three badge numbers. The rail chip wants the
             * total, the strip wants the two halves of the print run, and three separate
             * count() queries would be three table scans per poll for numbers that are
             * one GROUP BY apart.
             */
            $fulfillment = Badge::query()
                ->when($eventId, fn (Builder $query) => $query->whereHas(
                    'fursuit',
                    fn (Builder $fursuit) => $fursuit->where('event_id', $eventId)
                ))
                ->toBase()
                ->selectRaw('status_fulfillment, count(*) as aggregate')
                ->groupBy('status_fulfillment')
                ->pluck('aggregate', 'status_fulfillment');

            $badges = (int) $fulfillment->sum();

            /*
             * whereState rather than where('status', 'pending'): the column is a Spatie
             * state and the raw-string comparison in StatsOverview is what change 29
             * takes out.
             */
            $pendingFursuits = Fursuit::query()
                ->when($eventId, fn (Builder $query) => $query->where('event_id', $eventId))
                ->whereState('status', Pending::class)
                ->count();

            /*
             * The cards still to come out of a printer. Pending is a badge nobody has
             * queued yet, Processing is one sitting in a batch: both are work ahead, and
             * both disappear from the count the moment the card exists. The badge list's
             * status_fulfillment filter takes exactly these two values, so the strip's
             * number and the list the segment links to are the same set.
             */
            $unprintedBadges = (int) $fulfillment->only(['pending', 'processing'])->sum();

            /*
             * The counterpart: a card exists for this badge, whether or not the attendee
             * has collected it. The dead `printed` state is deliberately not in the set -
             * nothing transitions into it (only the raw `badges:print` command writes it)
             * and the badge list has no filter option for it, so counting it here would
             * put a number on the strip that its own link cannot show.
             */
            $printedBadges = (int) $fulfillment->only(['ready_for_pickup', 'picked_up'])->sum();

            $stopped = Printer::query()
                ->where('is_active', true)
                ->whereIn('condition', $this->stopConditions())
                ->count();

            $unverifiedCards = PrintJob::query()
                ->where('status', PrintJobStatusEnum::Printed)
                ->whereNull('verified_print_at')
                ->count();

            $unverifiedBatches = PrintBatch::query()
                ->whereHas('printJobs', fn (Builder $query) => $query
                    ->where('status', PrintJobStatusEnum::Printed)
                    ->whereNull('verified_print_at'))
                ->count();

            return [
                'badges' => $badges,
                'unprinted' => $unprintedBadges,
                'printed' => $printedBadges,
                'fursuits' => $pendingFursuits,
                'printers' => $stopped,
                'cards' => $unverifiedCards,
                'batches' => $unverifiedBatches,
            ];
        });
    }

    /**
     * @return array<int, string>
     */
    private function stopConditions(): array
    {
        return collect(PrinterConditionEnum::cases())
            ->filter(fn (PrinterConditionEnum $condition) => $condition->isStop())
            ->map(fn (PrinterConditionEnum $condition) => $condition->value)
            ->values()
            ->all();
    }
}
