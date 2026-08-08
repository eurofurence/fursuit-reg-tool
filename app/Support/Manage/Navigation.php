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
         * The seven Filament groups, minus the two that existed only because a page was
         * filed under its own heading: Badge Preview moves out of "Debug Tools" and in
         * beside the PDF generator, since both are read-only tools over the same data.
         */
        $groups = [
            ['label' => 'Overview', 'items' => [
                $this->item('Dashboard', 'layout-dashboard', 'manage.dashboard'),
            ]],
            ['label' => 'Events & Registration', 'items' => [
                $this->item('Events', 'calendar-days', 'manage.events.index', null, $this->permits('viewAny', Event::class)),
                $this->item('Badges', 'id-card', 'manage.badges.index', $badges['badges'] ?? null, $this->permits('viewAny', Badge::class)),
                $this->item('Fursuits', 'circle-user', 'manage.fursuits.index', $badges['fursuits'] ?? null, $this->permits('viewAny', Fursuit::class)),
                $this->item('Special Codes', 'qr-code', 'manage.special-codes.index', null, $this->permits('viewAny', SpecialCode::class)),
            ]],
            ['label' => 'Sales', 'items' => [
                $this->item('Checkouts', 'shopping-cart', 'manage.checkouts.index', null, $this->permits('viewAny', Checkout::class)),
            ]],
            ['label' => 'POS', 'items' => [
                $this->item('Machines', 'monitor', 'manage.machines.index', null, $this->permits('viewAny', Machine::class)),
                $this->item('Staff', 'users-round', 'manage.staff.index', null, $this->permits('viewAny', Staff::class)),
                $this->item('SumUp Readers', 'credit-card', 'manage.sumup-readers.index', null, $this->permits('viewAny', SumUpReader::class)),
                $this->item('TSE Clients', 'shield-check', 'manage.tse-clients.index', null, $this->permits('viewAny', TseClient::class)),
            ]],
            ['label' => 'Printing', 'items' => [
                $this->item('Printers', 'printer', 'manage.printers.index', $badges['printers'] ?? null, $this->permits('viewAny', Printer::class)),
                $this->item('Print Jobs', 'list-ordered', 'manage.print-jobs.index', null, $this->permits('viewAny', PrintJob::class)),
                $this->item('Print Batches', 'layers', 'manage.print-batches.index', $badges['batches'] ?? null, $this->permits('viewAny', PrintBatch::class)),
            ]],
            ['label' => 'User Management', 'items' => [
                $this->item('Users', 'users', 'manage.users.index', null, $this->permits('viewAny', User::class)),
            ]],
            ['label' => 'Tools', 'items' => [
                $this->item('PDF Generator', 'file-text', 'manage.tools.pdf'),
                $this->item('Badge Preview', 'eye', 'manage.tools.badge-preview'),
            ]],
            /*
             * One entry, not four. Settings carries its own vertical submenu inside the
             * page body (SettingsLayout.vue), so the three panes beside General are
             * reached from there rather than from the rail; putting all four here would
             * mean the same list twice, two columns apart, with two active markers.
             *
             * `manage.settings.general` is the pane /admin/settings renders, so the rail
             * item and the first pane are one URL.
             *
             * No `permits()` argument: reading how the convention is configured is open to
             * the whole panel, like Pickup Booths and the other tools, and each pane gates
             * its own writes on `manage-admin`.
             */
            ['label' => 'Configuration', 'items' => [
                $this->item('Settings', 'cog', 'manage.settings.general'),
            ]],
            ['label' => 'Maintenance', 'items' => [
                $this->item('DB Service', 'wrench', 'manage.maintenance.db-service', null, $this->permits('manage-admin')),
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
     * The top strip's own segments: the two numbers staff act on (plan 1.2).
     *
     * Same counts as the rail, from the same cached read, so the chip beside "Fursuits"
     * and the "pending" segment three elements to its left can never disagree. The strip
     * counts unverified *cards* while the rail chip counts the *batches* holding them:
     * two different questions, both named in plan 2.8 and 1.2, so both are computed.
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
                'label' => 'pending',
                'value' => $counts['fursuits'],
                'tone' => $counts['fursuits'] > 0 ? Status::WARN : Status::IDLE,
                'icon' => 'hourglass',
                // No filter in the link: the fursuit list declares
                // Filter::select('status')->default('pending') (plan 2.3), so its plain
                // URL already is the pending set this segment counts.
                'url' => $this->urlFor('manage.fursuits.index'),
            ];
        }

        if ($this->permits('viewAny', PrintJob::class)) {
            $segments[] = [
                'key' => 'unverified_cards',
                'label' => 'unverified',
                'value' => $counts['cards'],
                'tone' => $counts['cards'] > 0 ? Status::WARN : Status::IDLE,
                'icon' => 'id-card',
                'url' => $this->urlFor('manage.print-jobs.index', [
                    'filter' => ['status' => PrintJobStatusEnum::Printed->value, 'verified' => '0'],
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
            $badges = Badge::query()
                ->when($eventId, fn (Builder $query) => $query->whereHas(
                    'fursuit',
                    fn (Builder $fursuit) => $fursuit->where('event_id', $eventId)
                ))
                ->count();

            /*
             * whereState rather than where('status', 'pending'): the column is a Spatie
             * state and the raw-string comparison in StatsOverview is what change 29
             * takes out.
             */
            $pendingFursuits = Fursuit::query()
                ->when($eventId, fn (Builder $query) => $query->where('event_id', $eventId))
                ->whereState('status', Pending::class)
                ->count();

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
