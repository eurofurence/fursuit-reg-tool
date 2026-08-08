<?php

namespace App\Support\Manage;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Builds the list-page envelope every manage index sends to the client:
 * rows, columns, filters, sort, search, meta, and the three action sets.
 *
 * All thirteen modules share this so sorting, searching, filtering, pagination and
 * column-visibility behave identically and are worth testing only once.
 *
 * Request contract: ?tab=&search=&sort=&dir=&page=&per_page=&filter[key]=
 */
final class Table
{
    private string $name = 'table';

    /** @var array<int, Column> */
    private array $columns = [];

    /** @var array<int, Filter> */
    private array $filters = [];

    /** @var array<int, Tab> */
    private array $tabs = [];

    private ?string $defaultSortKey = null;

    private string $defaultSortDir = 'asc';

    private ?Closure $rowsUsing = null;

    private ?Closure $recordUrlUsing = null;

    private ?Closure $rowActionsUsing = null;

    /** @var array<int, Action> */
    private array $bulkActions = [];

    /** @var array<int, Action> */
    private array $pageActions = [];

    private int $perPage = 25;

    /** @var array<int, int> */
    private array $perPageOptions = [10, 25, 50, 100];

    private function __construct(private readonly Builder $query) {}

    public static function make(Builder $query): self
    {
        return new self($query);
    }

    /**
     * Identifies the table for per-user column-visibility persistence.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @param  array<int, Column>  $columns
     */
    public function columns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * @param  array<int, Filter>  $filters
     */
    public function filters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    /**
     * The preset views this table offers, in the order they are shown, the first being the
     * default. A module that passes none renders exactly as it did before tabs existed:
     * the envelope carries an empty list and the client draws no strip.
     *
     * @param  array<int, Tab>  $tabs
     */
    public function tabs(array $tabs): self
    {
        $this->tabs = array_values($tabs);

        return $this;
    }

    public function defaultSort(string $key, string $dir = 'asc'): self
    {
        $this->defaultSortKey = $key;
        $this->defaultSortDir = $dir;

        return $this;
    }

    /**
     * Maps a record to its cell values, keyed by column key.
     *
     * @param  Closure(Model): array<string, mixed>  $callback
     */
    public function rows(Closure $callback): self
    {
        $this->rowsUsing = $callback;

        return $this;
    }

    /**
     * Where clicking the row navigates.
     *
     * @param  Closure(Model): ?string  $callback
     */
    public function recordUrl(Closure $callback): self
    {
        $this->recordUrlUsing = $callback;

        return $this;
    }

    /**
     * @param  Closure(Model): array<int, Action>  $callback
     */
    public function rowActions(Closure $callback): self
    {
        $this->rowActionsUsing = $callback;

        return $this;
    }

    /**
     * @param  array<int, Action>  $actions
     */
    public function bulkActions(array $actions): self
    {
        $this->bulkActions = $actions;

        return $this;
    }

    /**
     * @param  array<int, Action>  $actions
     */
    public function pageActions(array $actions): self
    {
        $this->pageActions = $actions;

        return $this;
    }

    public function perPage(int $perPage): self
    {
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * The four tables the old panel ran with ->paginated(false) become perPage 200 with the
     * pager still visible, which needs an option the stock list does not carry.
     *
     * @param  array<int, int>  $options
     */
    public function perPageOptions(array $options): self
    {
        $this->perPageOptions = array_values(array_unique($options));

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->rememberReturnUrl($request);

        $search = trim((string) $request->input('search', ''));
        $filterValues = $this->resolveFilterValues($request);

        // The tab is resolved and counted before anything else touches the query, because
        // a count is of the tab's own view of the table and must not see the chip filters
        // or the search term. It narrows first for the same reason it is drawn first: it
        // picks the view, and the filters then narrow inside it.
        $tab = $this->resolveTab($request);
        $tabs = $this->tabPayload($tab);

        $tab?->applyTo($this->query);

        $this->applyFilters($filterValues);
        $this->applySearch($search);
        $sort = $this->applySort($request);

        $perPage = $this->resolvePerPage($request);
        $paginator = $this->query->paginate($perPage)->withQueryString();

        return $this->tabEnvelope($tabs) + [
            'name' => $this->name,
            'rows' => collect($paginator->items())->map(fn (Model $record) => [
                'id' => $record->getKey(),
                'url' => $this->recordUrlUsing ? ($this->recordUrlUsing)($record) : null,
                'cells' => $this->formatCells(
                    $this->rowsUsing ? ($this->rowsUsing)($record) : $record->attributesToArray()
                ),
                'actions' => $this->rowActionsUsing
                    ? array_map(fn (Action $action) => $action->toArray(), array_values(($this->rowActionsUsing)($record)))
                    : [],
            ])->all(),
            'columns' => array_map(fn (Column $column) => $column->toArray(), $this->columns),
            'hiddenColumns' => $this->hiddenColumns(),
            'filters' => array_map(
                fn (Filter $filter) => $filter->toArray() + ['value' => $filterValues[$filter->key]],
                $this->filters,
            ),
            'sort' => $sort,
            'search' => $search,
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'perPageOptions' => $this->resolvedPerPageOptions(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'bulkActions' => array_map(fn (Action $action) => $action->toArray(), $this->bulkActions),
            'pageActions' => array_map(fn (Action $action) => $action->toArray(), $this->pageActions),
        ];
    }

    /**
     * Remembers the list URL, query string and all, so a save can come back to it.
     *
     * Tab, filters, search, sort and page live entirely in the URL, so a controller that
     * finishes an edit with `redirect()->route('...index')` lands on the bare list and
     * every one of them is gone. That is the whole of the "my filters reset when I save"
     * complaint: nothing was forgotten, the redirect just never carried it.
     *
     * Session rather than a `?return=` parameter because the round trip is index → edit →
     * POST → redirect, and the middle two are the module's own pages: threading the URL
     * through every edit link and every form would be the same fix written seventeen
     * times. Keyed by table name, so two modules cannot send each other to the wrong list.
     */
    private function rememberReturnUrl(Request $request): void
    {
        if ($request->isMethod('GET')) {
            session()->put("manage.table.{$this->name}.return", $request->fullUrl());
        }
    }

    /**
     * The list URL to go back to after an edit, or $fallback on a first visit.
     *
     * Only ever returns a URL this app itself served to this session, so there is nothing
     * here an outside caller can point at another host.
     */
    public static function returnUrl(string $name, string $fallback): string
    {
        $stored = session("manage.table.{$name}.return");

        return is_string($stored) && $stored !== '' ? $stored : $fallback;
    }

    /**
     * Gives every column a last look at its own cell before it is serialised. Today that
     * matters for money only, which converts cents to euros here so no row transformer has
     * to remember to divide.
     *
     * @param  array<string, mixed>  $cells
     * @return array<string, mixed>
     */
    private function formatCells(array $cells): array
    {
        foreach ($this->columns as $column) {
            if (array_key_exists($column->key, $cells)) {
                $cells[$column->key] = $column->formatCell($cells[$column->key]);
            }
        }

        return $cells;
    }

    /**
     * Column keys the current user has hidden, defaulting to the declared hidden set.
     *
     * @return array<int, string>
     */
    private function hiddenColumns(): array
    {
        $stored = session("manage.table.{$this->name}.hidden");

        if (is_array($stored)) {
            return array_values($stored);
        }

        return collect($this->columns)
            ->filter(fn (Column $column) => $column->isHiddenByDefault())
            ->map(fn (Column $column) => $column->key)
            ->values()
            ->all();
    }

    /**
     * The `tabs` key, or no key at all on a table that declares none.
     *
     * Absent rather than empty on purpose. These envelopes are spread into a page's props,
     * and an Inertia prop a page has not declared falls through to `$attrs`, which on a
     * two-root page (every list page: `<Head>` plus `<ManageLayout>`) is a dev warning on
     * a module that has nothing to do with tabs. Sending the key only when there is
     * something in it means the sixteen tabless modules receive byte-identical props and
     * none of their pages is touched. A module that declares tabs adds the prop to its
     * page, exactly as it already does for `filters` or `sort`.
     *
     * Each entry carries `active`, the server's own resolution, which is what a test
     * asserts against. TabBar does not read it - `tabs` is not one of the five props a
     * partial visit reloads, so a server-sent flag would freeze on the first switch - and
     * re-derives the same answer from the URL under the same two fallback rules. See
     * resolveTab below and TabBar.vue.
     *
     * @param  array<int, array<string, mixed>>  $tabs
     * @return array<string, mixed>
     */
    private function tabEnvelope(array $tabs): array
    {
        return $tabs === [] ? [] : ['tabs' => $tabs];
    }

    /**
     * The active tab, or null on a table that declares none.
     *
     * An unrecognised `?tab=` falls back to the first declared tab rather than showing an
     * empty list or 404ing: the key is a hand-editable, bookmarkable, renameable token, so
     * a stale one has to land somewhere sensible. TabBar.vue applies the same fallback so
     * the strip's selected tab and the rows below it cannot disagree.
     */
    private function resolveTab(Request $request): ?Tab
    {
        if ($this->tabs === []) {
            return null;
        }

        $requested = $request->input('tab');

        return collect($this->tabs)->first(fn (Tab $tab) => $tab->key === $requested) ?? $this->tabs[0];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tabPayload(?Tab $active): array
    {
        return array_map(fn (Tab $tab) => $tab->toArray() + [
            'active' => $active !== null && $active->key === $tab->key,
            'count' => $tab->isCounted() ? $this->countTab($tab) : null,
        ], $this->tabs);
    }

    /**
     * One COUNT per counted tab, and only for tabs that asked. The query is cloned so the
     * count cannot leave its constraint behind on the query that fetches the rows, and
     * getCountForPagination is used rather than count() so a grouped base query is counted
     * the same way the paginator counts it.
     */
    private function countTab(Tab $tab): int
    {
        $counting = clone $this->query;

        $tab->applyTo($counting);

        return $counting->toBase()->getCountForPagination();
    }

    /**
     * A filter absent from the request falls back to its declared default, which is how
     * the fursuit list keeps opening on "pending" the way the old panel table did.
     *
     * Filter::CLEARED is the third state: the operator picked the "all" placeholder or
     * hit Clear, and the filter must stay off rather than be re-defaulted. Without it
     * "not set" and "explicitly empty" are the same request and a defaulted filter can
     * never be turned off.
     *
     * @return array<string, mixed>
     */
    private function resolveFilterValues(Request $request): array
    {
        $values = [];

        foreach ($this->filters as $filter) {
            $raw = $request->input("filter.{$filter->key}");

            $values[$filter->key] = match (true) {
                $raw === null => $filter->defaultValue(),
                $raw === Filter::CLEARED => $filter->emptyValue(),
                default => $filter->normalize($raw),
            };
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function applyFilters(array $values): void
    {
        foreach ($this->filters as $filter) {
            $value = $values[$filter->key];

            if ($filter->isActive($value)) {
                $filter->applyTo($this->query, $value);
            }
        }
    }

    private function applySearch(string $search): void
    {
        if ($search === '') {
            return;
        }

        $searchable = array_filter($this->columns, fn (Column $column) => $column->isSearchable());

        if ($searchable === []) {
            return;
        }

        $operator = $this->query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $term = '%'.$search.'%';

        $this->query->where(function (Builder $query) use ($searchable, $operator, $term) {
            foreach ($searchable as $column) {
                $key = $column->resolvedSearchKey();

                if (str_contains($key, '.')) {
                    // Split at the last dot, not the first: `fursuit.user.name` is the
                    // nested relation `fursuit.user` and the attribute `name`, and
                    // whereHas takes a dotted relation path directly. Splitting at the
                    // first dot handed `user.name` to where() as a column name and the
                    // badge list's Owner search 500'd on it. Single-dot keys are
                    // unaffected. The attribute is qualified because these tables are
                    // routinely joined into the outer query as well.
                    $relation = str($key)->beforeLast('.')->toString();
                    $attribute = str($key)->afterLast('.')->toString();

                    $query->orWhereHas(
                        $relation,
                        fn (Builder $q) => $q->where($q->qualifyColumn($attribute), $operator, $term)
                    );

                    continue;
                }

                $query->orWhere($query->qualifyColumn($key), $operator, $term);
            }
        });
    }

    /**
     * @return array{key: string|null, dir: string}
     */
    private function applySort(Request $request): array
    {
        $requestedKey = $request->input('sort');
        $dir = strtolower((string) $request->input('dir', $this->defaultSortDir)) === 'desc' ? 'desc' : 'asc';

        $column = collect($this->columns)->first(
            fn (Column $column) => $column->isSortable() && $column->key === $requestedKey
        );

        if (! $column) {
            if ($this->defaultSortKey) {
                // The default sort goes through the declared column's own sort callback
                // when it has one, so the first page load is ordered exactly the way
                // clicking that header orders it. The badge list defaults to
                // `sort_attendee_id`, whose sort is a numeric comparison on a string
                // column; without this the opening page was sorted as text and only the
                // first click made it right.
                $default = collect($this->columns)->first(
                    fn (Column $candidate) => $candidate->key === $this->defaultSortKey
                );

                if ($default && $callback = $default->sortCallback()) {
                    $callback($this->query, $this->defaultSortDir);
                } else {
                    $this->query->orderBy($this->defaultSortKey, $this->defaultSortDir);
                }
            }

            return ['key' => $this->defaultSortKey, 'dir' => $this->defaultSortDir];
        }

        if ($callback = $column->sortCallback()) {
            $callback($this->query, $dir);
        } else {
            $this->query->orderBy($column->resolvedSortKey(), $dir);
        }

        return ['key' => $column->key, 'dir' => $dir];
    }

    private function resolvePerPage(Request $request): int
    {
        $requested = (int) $request->input('per_page', $this->perPage);

        return in_array($requested, $this->resolvedPerPageOptions(), true) ? $requested : $this->perPage;
    }

    /**
     * A table that declares a per-page outside the standard list still needs it offered,
     * otherwise its own default is unreachable from the pager.
     *
     * @return array<int, int>
     */
    private function resolvedPerPageOptions(): array
    {
        $options = $this->perPageOptions;

        if (! in_array($this->perPage, $options, true)) {
            $options[] = $this->perPage;
        }

        sort($options);

        return array_values($options);
    }
}
