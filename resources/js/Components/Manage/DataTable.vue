<script setup>
/**
 * Renders the list envelope produced by App\Support\Manage\Table: toolbar, table, footer.
 *
 * Everything domain-specific (labels, tones, formatting, which actions exist) arrives
 * from the server. This component only knows the cell types documented on
 * App\Support\Manage\Column.
 *
 * Why it owns the toolbar and the footer. Each list page used to mount FilterBar above this
 * component and Pagination below it, while this component drew a strip of its own for the
 * column toggle. Filters and the column toggle therefore sat on two stacked full-height
 * bands, which is height that says nothing, and no page could put them on one row without
 * every page reimplementing the row. Passing one envelope to one component removes the
 * split rather than negotiating across it: the page hands over `table` and gets a filter
 * row with the column toggle on its right end, the rows, and the pager.
 *
 * The envelope already carries `filters`, `search` and `meta`, so absorbing the two
 * neighbours cost the pages no new props. `searchable` is the exception and is explained on
 * the prop.
 *
 * FilterBar and Pagination are still standalone components. Staff/Form.vue drives a
 * hand-rolled table for RFID tags - server-declared row actions could not express its
 * inline editing - and mounts both directly.
 */
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import ActionButton from './ActionButton.vue';
import ColumnMenu from './ColumnMenu.vue';
import FilterBar from './FilterBar.vue';
import ManageIcon from './ManageIcon.vue';
import Pagination from './Pagination.vue';
import StatusBadge from './StatusBadge.vue';
import TabBar from './TabBar.vue';
import { resolve, toneText } from './tones.js';
import { activeTabKey, useTableQuery } from './useTableQuery.js';

const props = defineProps({
  table: { type: Object, required: true },
  /**
   * Whether to offer the search box.
   *
   * Opt-in, and deliberately not derived from the envelope: `searchable` is a property of
   * the columns on the server and is not serialised, and a box that silently narrows
   * nothing is worse than no box. This is the same choice each page was already making by
   * mounting FilterBar or not, moved onto a prop, so a table with no searchable column
   * (events, special codes, SumUp readers, TSE clients) keeps showing none.
   */
  searchable: { type: Boolean, default: false },
});

const { toggleSort } = useTableQuery();

const page = usePage();

const selected = ref([]);

/**
 * The query string the selection was taken against, while it covers every matching record
 * rather than the page on screen; null the rest of the time.
 *
 * It is both the flag and the guard. A selection that reaches past this page cannot be
 * pruned to the rows that are rendered - that is the whole point of it - so the prune
 * below has to know it is looking at one, and something else has to decide when it is
 * stale. The query string is that something: the ids answer "everything matching *this*
 * view", so they stop meaning anything the moment the view changes, and a filter, a search
 * or a tab is exactly a change of query string. Paging is too, which costs nothing here -
 * an operator who has ticked all 9 000 badges and then steps a page is not still holding a
 * selection they can reason about.
 */
const selectionScope = ref(null);

const clearSelection = () => {
  selected.value = [];
  selectionScope.value = null;
};

const hidden = ref([...(props.table.hiddenColumns ?? [])]);

watch(
  () => props.table.hiddenColumns,
  (next) => {
    hidden.value = [...(next ?? [])];
  },
);

/*
 * A reload can drop rows out from under the selection; keep only what is still visible.
 *
 * This is not the same thing as deselecting after a bulk action, which the bulk bar does
 * on the action's own `completed`. Pruning only clears rows that left the page, so a bulk
 * action whose records stay visible - archiving machines while the archived filter is on
 * "All machines" - would otherwise finish with the same rows still ticked.
 */
watch(
  () => props.table.rows,
  (rows) => {
    anchor.value = null;

    // A selection that covers the whole result set is not prunable to the rows on screen.
    // It survives a poll, which is what makes ticking 9 000 badges usable at all, and it
    // is dropped whole the moment the view it was taken against is not the view any more.
    if (selectionScope.value !== null) {
      if (selectionScope.value !== window.location.search) {
        clearSelection();
      }

      return;
    }

    const ids = rows.map((row) => row.id);
    selected.value = selected.value.filter((id) => ids.includes(id));
  },
);

/**
 * Where the last row-checkbox click landed, as the index shift-click extends from.
 *
 * Reset whenever the rows change, because it is an index into the page that is on screen:
 * carrying it across a sort or a page change would extend from a row the operator never
 * clicked.
 */
const anchor = ref(null);

/**
 * Row selection, including shift-click for a range.
 *
 * Selection is driven from `selected` with `:checked` rather than `v-model`, because the
 * range case has to rewrite the whole array from inside the click handler and v-model's
 * own change handler would land afterwards and undo part of it. The checkbox's new state
 * is already applied by the time a click listener runs, so `event.target.checked` is what
 * the operator asked for, and the whole range follows it - shift-clicking with the box
 * going off clears the range the same way it sets it.
 */
const onSelectClick = (event, index) => {
  const rows = props.table.rows;
  const checked = event.target.checked;

  const ids =
    event.shiftKey && anchor.value !== null && anchor.value < rows.length
      ? rows
          .slice(Math.min(anchor.value, index), Math.max(anchor.value, index) + 1)
          .map((row) => row.id)
      : [rows[index].id];

  selected.value = checked
    ? [...new Set([...selected.value, ...ids])]
    : selected.value.filter((id) => !ids.includes(id));

  anchor.value = index;
};

const visibleColumns = computed(() => props.table.columns.filter((column) => !hidden.value.includes(column.key)));
const toggleableColumns = computed(() => props.table.columns.filter((column) => column.toggleable));

const filters = computed(() => props.table.filters ?? []);

/*
 * Preset views. Declared on the server exactly like columns and filters, so a module gains
 * them by adding `->tabs([...])` to its Table and this file needs nothing: the strip is
 * here, above the toolbar, or it is not there at all. Sixteen modules declare none, are
 * sent no `tabs` key at all, and render as they always have.
 */
const tabs = computed(() => props.table.tabs ?? []);

/*
 * What the tabs control: the toolbar that refines the view, the rows, and the pager, which
 * is this component's existing root. It only carries the tabpanel role when there is a
 * tablist above it, because a tabpanel with no tablist is a lie; the id is unconditional
 * so the two cases stay one piece of markup. Named per table, so two lists on one screen
 * could not point at each other.
 */
const panelId = computed(() => `table-${props.table.name}`);

// Through the shared resolver, off the same reactive URL TabBar uses, so the panel is
// labelled by the tab that is actually selected rather than by a second opinion.
const activeTabId = computed(() =>
  tabs.value.length ? `${panelId.value}-tab-${activeTabKey(tabs.value, page.url)}` : null,
);

/*
 * No controls, no band. A table with nothing to filter, nothing to search and nothing to
 * hide would otherwise get an empty strip above it, which is the height the merge was
 * supposed to remove. Four modules are in exactly that position.
 */
const showToolbar = computed(
  () => props.searchable || filters.value.length > 0 || toggleableColumns.value.length > 0,
);

/*
 * Membership is asked once per render rather than per row, because a selection that
 * covers every match is thousands of ids long and the header box alone would otherwise
 * scan it once for each row on the page.
 */
const selectedIds = computed(() => new Set(selected.value));

/*
 * Every row on this page is ticked - which is no longer the same statement as "the
 * selection is this page", now that it can reach past it. Counting was the old test and it
 * silently stopped being true.
 */
const allSelected = computed(
  () => props.table.rows.length > 0 && props.table.rows.every((row) => selectedIds.value.has(row.id)),
);

/*
 * Some rows on this page ticked, but not all. The header box has to say that as its own
 * third state rather than reading as empty: with the box unticked over a part-selected
 * page, the bulk bar's count is the only thing contradicting it, and the bulk bar is at
 * the top of a list the operator may have scrolled past.
 */
const someSelected = computed(() => selected.value.length > 0 && !allSelected.value);

const toggleAll = () => {
  if (allSelected.value || selectionScope.value !== null) {
    clearSelection();

    return;
  }

  selected.value = props.table.rows.map((row) => row.id);
};

/*
 * Selecting past the page.
 *
 * The header checkbox can only ever tick what is rendered, so every bulk action used to
 * stop at one page: a print run of the four hundred badges a filter had just isolated was
 * sixteen trips through the pager, or a per-page of 400 rendered to tick one box. Offered
 * only once the page itself is fully ticked, which is the point at which "and the rest"
 * is the obvious next thing to want, and only when there is a rest to have.
 */
const selectingAll = ref(false);

const canSelectAll = computed(
  () =>
    selectionScope.value === null &&
    allSelected.value &&
    (props.table.meta?.total ?? 0) > props.table.rows.length,
);

/**
 * The ids come from the server rather than from anything the client can derive, because
 * "everything matching" is a fact about the query - the filters, the search, the tab and
 * the event scope, resolved together - and only App\Support\Manage\Table holds all of it.
 * They are fetched as a partial reload of `meta`, so the answer is the same builder that
 * produced the page, and the header keeps `select_all` out of the address bar.
 *
 * The reload is one request that returns as many keys as the filter matches; the count is
 * on the button, so nothing here is a surprise.
 */
const selectAllMatching = () => {
  selectingAll.value = true;

  router.reload({
    only: ['meta'],
    headers: { 'X-Table-Select-All': '1' },
    onSuccess: () => {
      const ids = props.table.meta?.allIds;

      if (!ids) {
        return;
      }

      selected.value = [...ids];
      selectionScope.value = window.location.search;
    },
    onFinish: () => {
      selectingAll.value = false;
    },
  });
};

/*
 * Unchanged by the toolbar merge, and it has to stay here: ColumnMenu draws the list and
 * reports the click, but the hidden set and the POST that persists it per user per table
 * (App\Http\Controllers\Manage\TableColumnController, session-backed, read again by
 * Table::hiddenColumns) belong to the component that renders the columns.
 */
const toggleColumn = (key) => {
  hidden.value = hidden.value.includes(key)
    ? hidden.value.filter((existing) => existing !== key)
    : [...hidden.value, key];

  router.post(
    route('admin.tables.columns', props.table.name),
    { hidden: hidden.value },
    { preserveScroll: true, preserveState: true },
  );
};

const align = {
  left: 'text-left',
  right: 'text-right',
  center: 'text-center',
};

const cell = (row, key) => row.cells?.[key] ?? null;

const isEmpty = (value) => value === null || value === undefined || value === '';

const numberDisplay = (value) => {
  if (isEmpty(value)) {
    return null;
  }

  if (typeof value === 'object') {
    return value.display ?? null;
  }

  // A non-numeric value would format as the literal "NaN", which reads as broken
  // data rather than an empty cell, so it falls through to the placeholder.
  if (Number.isNaN(Number(value))) {
    return null;
  }

  return new Intl.NumberFormat('en-GB').format(value).replace(/,/g, ' ');
};

// Money is formatted once on the server, from integer cents, so no call site can
// forget the divide. The client only unwraps the envelope.
const moneyDisplay = (value) => (typeof value === 'object' && value !== null ? value.display ?? null : value);

const numeric = (type) => type === 'number' || type === 'money';

const copy = async (value) => {
  try {
    await navigator.clipboard.writeText(String(value));
  } catch {
    // Clipboard is unavailable outside a secure context; nothing to recover from.
  }
};

const open = (row, event) => {
  if (!row.url || event.target.closest('a, button, input, select, label')) {
    return;
  }

  router.visit(row.url);
};
</script>

<template>
  <!--
    Two roots, and the split is the point. The strip picks the view; everything under it -
    the toolbar that refines the view, the rows, the pager - is the view. A tab may not sit
    inside the panel it controls, so the strip is the component's sibling root rather than
    its first child, and the panel keeps the element and the classes it has always had. A
    module with no tabs renders one root, unchanged, with no strip and no empty band.
  -->
  <TabBar v-if="tabs.length" :tabs="tabs" :panel-id="panelId" />

  <div
    :id="panelId"
    class="flex min-w-0 flex-col"
    :role="tabs.length ? 'tabpanel' : null"
    :aria-labelledby="activeTabId"
  >
    <!-- Bulk bar: only present while something is selected, so it never costs vertical space. -->
    <div
      v-if="selected.length && table.bulkActions.length"
      class="flex min-h-10 flex-wrap items-center gap-x-2 gap-y-1 border-b border-hairline bg-mg-surface-2 px-3 py-1.5"
    >
      <span class="text-[12px] text-fg-2">{{ selected.length }} selected</span>

      <!--
        The way past the page, and it is a link rather than a second checkbox state: it
        costs a request and it changes what the buttons next to it will act on, which is
        a thing an operator should have to say rather than something a tick can imply.
        It names the number so "print everything that matches" is never a guess.
      -->
      <button
        v-if="canSelectAll"
        type="button"
        class="rounded px-1.5 py-0.5 text-[12px] text-state-live underline decoration-state-live/40 underline-offset-2 outline-none transition-colors hover:bg-state-live/10 focus-visible:ring-1 focus-visible:ring-state-live/40 disabled:opacity-50"
        :disabled="selectingAll"
        @click="selectAllMatching"
      >
        {{ selectingAll ? 'Selecting…' : `Select all ${table.meta.total} matching` }}
      </button>

      <span v-else-if="selectionScope !== null" class="text-[12px] text-fg-3">
        every record matching the current filters
      </span>

      <ActionButton
        v-for="action in table.bulkActions"
        :key="action.name"
        :action="action"
        :data="{ ids: selected }"
        @completed="clearSelection"
      />
      <button
        type="button"
        class="ml-auto text-[12px] text-fg-3 transition-colors hover:text-fg-1"
        @click="clearSelection"
      >
        Clear
      </button>
    </div>

    <!--
      The one toolbar row: search and filter chips on the left, the column toggle hard
      right, on the same line. py-2 with a min height rather than a fixed height, because
      the row has to grow by whole chip rows as filters accumulate.

      Three things keep it off the horizontal scrollbar at narrow widths. FilterBar is
      flex-1 and wraps its own chips internally, so it shrinks before anything overflows.
      Its 12rem floor means that once the window cannot seat that floor alongside the
      toggle, flex-wrap on this row drops the toggle onto a line of its own rather than
      squeezing the search box to nothing. And ml-auto keeps the toggle at the right edge on
      either line, so it is in the same place whether or not the row wrapped.
    -->
    <div
      v-if="showToolbar"
      class="flex min-h-11 flex-wrap items-center gap-x-2 gap-y-1.5 border-b border-hairline bg-mg-surface-1 px-3 py-2"
    >
      <FilterBar
        v-if="searchable || filters.length"
        :filters="filters"
        :search="table.search ?? ''"
        :searchable="searchable"
      />

      <ColumnMenu
        v-if="toggleableColumns.length"
        class="ml-auto"
        :columns="toggleableColumns"
        :hidden="hidden"
        @toggle="toggleColumn"
      />
    </div>

    <div class="overflow-x-auto">
      <table class="w-full border-collapse text-[13px]">
        <thead>
          <tr class="border-y border-hairline bg-mg-surface-2">
            <th v-if="table.bulkActions.length" class="w-8 px-3">
              <!--
                `indeterminate` is a DOM property with no matching attribute, so it can only
                be bound, never written in the markup. Setting it is also what makes a
                native checkbox report "mixed" to the accessibility tree, so no aria-checked
                is needed alongside it. The label says what the click will do, which is the
                one thing the mixed state does not already announce.
              -->
              <input
                type="checkbox"
                class="cursor-pointer"
                :checked="allSelected"
                :indeterminate="someSelected"
                :aria-label="allSelected ? 'Deselect all rows' : 'Select all rows'"
                @change="toggleAll"
              />
            </th>
            <th
              v-for="column in visibleColumns"
              :key="column.key"
              class="h-7 px-3 text-[11px] font-medium uppercase tracking-wide text-fg-2"
              :class="align[column.align] ?? align.left"
              :style="column.width ? { width: column.width } : null"
            >
              <button
                v-if="column.sortable"
                type="button"
                class="inline-flex items-center gap-1 transition-colors hover:text-fg-1"
                @click="toggleSort(column, table.sort)"
              >
                {{ column.label }}
                <ManageIcon
                  v-if="table.sort?.key === column.key"
                  :name="table.sort.dir === 'asc' ? 'chevron-up' : 'chevron-down'"
                  :size="12"
                />
              </button>
              <span v-else>{{ column.label }}</span>
            </th>
            <th v-if="table.rows.some((row) => row.actions.length)" class="px-3" />
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="(row, rowIndex) in table.rows"
            :key="row.id"
            class="border-b border-hairline/60 transition-colors hover:bg-mg-surface-2"
            :class="row.url ? 'cursor-pointer' : ''"
            @click="open(row, $event)"
          >
            <td v-if="table.bulkActions.length" class="px-3">
              <input
                type="checkbox"
                :checked="selectedIds.has(row.id)"
                class="cursor-pointer"
                :aria-label="`Select row ${row.id}`"
                :title="'Shift-click to select a range'"
                @click="onSelectClick($event, rowIndex)"
              />
            </td>

            <td
              v-for="column in visibleColumns"
              :key="column.key"
              class="h-8 px-3 whitespace-nowrap text-fg-1"
              :class="[align[column.align] ?? align.left, numeric(column.type) ? 'tabular-nums' : '']"
            >
              <template v-if="column.type === 'badge'">
                <!--
                  A cell that carries a `url` renders as a link. the old panel put a URL on
                  several plain columns (the badge list's Fursuit, Owner and Print Jobs
                  cells); the type stays what it was and the link is an extra key on the
                  cell, so nothing that sends a bare value changes.
                -->
                <a
                  v-if="cell(row, column.key)?.url"
                  :href="cell(row, column.key).url"
                  class="inline-flex"
                  @click.stop
                >
                  <StatusBadge :status="cell(row, column.key)" />
                </a>
                <StatusBadge v-else :status="cell(row, column.key)" />
                <!--
                  the old panel's TextColumn->badge()->description(). The batch list's Progress
                  column is the one cell that needs a second line under the chip; every
                  other badge cell sends no description and renders exactly as before.
                -->
                <span
                  v-if="cell(row, column.key)?.description"
                  class="block text-[11px] text-fg-3"
                >{{ cell(row, column.key).description }}</span>
              </template>

              <template v-else-if="column.type === 'number'">
                <span v-if="numberDisplay(cell(row, column.key)) !== null">
                  {{ numberDisplay(cell(row, column.key)) }}
                  <span
                    v-if="cell(row, column.key)?.description"
                    class="block text-[11px] text-fg-3"
                  >{{ cell(row, column.key).description }}</span>
                </span>
                <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
              </template>

              <template v-else-if="column.type === 'money'">
                <span v-if="!isEmpty(moneyDisplay(cell(row, column.key)))">
                  {{ moneyDisplay(cell(row, column.key)) }}
                  <span
                    v-if="cell(row, column.key)?.description"
                    class="block text-[11px] text-fg-3"
                  >{{ cell(row, column.key).description }}</span>
                </span>
                <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
              </template>

              <!--
                Two shapes, both fixed here: a rounded rectangle for thumbnails of
                things (badge artwork) and a round avatar for thumbnails of someone
                (the fursuit list's image, the badge list's fursuit.image), which is
                what the old panel's ImageColumn->circular() drew. The column declares which
                with Column::image(...)->circular(); the client picks no geometry of its
                own, so every image cell in the panel is one of these two.
              -->
              <template v-else-if="column.type === 'image'">
                <img
                  v-if="!isEmpty(cell(row, column.key))"
                  :src="cell(row, column.key)"
                  alt=""
                  class="border border-hairline object-cover"
                  :class="column.circular ? 'size-7 rounded-full' : 'h-6 w-10 rounded-sm'"
                />
                <span v-else class="text-fg-3">—</span>
              </template>

              <template v-else-if="column.type === 'bool'">
                <ManageIcon
                  :name="cell(row, column.key) ? 'circle-check' : 'circle-x'"
                  :size="15"
                  :class="cell(row, column.key) ? 'inline text-state-ok' : 'inline text-fg-3'"
                />
              </template>

              <template v-else-if="column.type === 'icon'">
                <ManageIcon
                  :name="cell(row, column.key)?.icon"
                  :size="15"
                  class="inline"
                  :class="resolve(toneText, cell(row, column.key)?.tone)"
                  :title="cell(row, column.key)?.title"
                />
              </template>

              <template v-else-if="column.type === 'color'">
                <span class="inline-flex items-center gap-1.5">
                  <span
                    class="inline-block size-3.5 rounded-sm border border-hairline"
                    :style="{ backgroundColor: cell(row, column.key) }"
                  />
                  <span class="font-mono text-[12px] text-fg-2">{{ cell(row, column.key) }}</span>
                </span>
              </template>

              <template v-else-if="column.type === 'copyable'">
                <button
                  v-if="!isEmpty(cell(row, column.key))"
                  type="button"
                  class="group inline-flex items-center gap-1 font-mono text-[12px] text-fg-1"
                  :title="`Copy ${cell(row, column.key)}`"
                  @click.stop="copy(cell(row, column.key))"
                >
                  {{ cell(row, column.key) }}
                  <ManageIcon name="copy" :size="12" class="opacity-0 transition-opacity group-hover:opacity-60" />
                </button>
                <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
              </template>

              <template v-else-if="column.type === 'toggle'">
                <input
                  type="checkbox"
                  class="cursor-pointer"
                  :checked="cell(row, column.key)?.value"
                  :aria-label="column.label"
                  @click.stop
                  @change="router.post(cell(row, column.key).url, {}, { preserveScroll: true })"
                />
              </template>

              <template v-else-if="column.type === 'datetime'">
                <span v-if="!isEmpty(cell(row, column.key))" :title="cell(row, column.key)?.title">
                  <span class="tabular-nums">{{ cell(row, column.key)?.display ?? cell(row, column.key) }}</span>
                  <span
                    v-if="cell(row, column.key)?.description"
                    class="block text-[11px] text-fg-3"
                  >{{ cell(row, column.key).description }}</span>
                </span>
                <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
              </template>

              <template v-else>
                <a
                  v-if="cell(row, column.key)?.url"
                  :href="cell(row, column.key).url"
                  class="underline decoration-hairline underline-offset-2 transition-colors hover:text-state-live"
                  :title="cell(row, column.key)?.title"
                  @click.stop
                >
                  {{ cell(row, column.key).display }}
                </a>
                <span v-else-if="!isEmpty(cell(row, column.key))" :title="cell(row, column.key)?.title">
                  {{ cell(row, column.key)?.display ?? cell(row, column.key) }}
                </span>
                <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
              </template>
            </td>

            <td v-if="table.rows.some((r) => r.actions.length)" class="px-3">
              <div class="flex justify-end gap-1">
                <ActionButton
                  v-for="action in row.actions"
                  :key="action.name"
                  :action="action"
                  icon-only
                />
              </div>
            </td>
          </tr>

          <tr v-if="!table.rows.length">
            <td :colspan="visibleColumns.length + 2" class="h-24 text-center text-[13px] text-fg-3">
              Nothing matches the current filters.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!--
      Between the rows and the pager, which is the only place a running total belongs: under
      the column it totals, above the control that changes which rows were counted. The
      checkout list is the one caller, for the old panel's Sum summariser.
    -->
    <slot name="summary" />

    <!--
      `meta` is part of the envelope every manage list sends, so the footer comes with the
      table and a page passes nothing extra. The guard is for a caller that hands over a
      table object it assembled without one; nothing in the panel does today.
    -->
    <Pagination v-if="table.meta" :meta="table.meta" />
  </div>
</template>
