<script setup>
/**
 * The list-page filter controls: search on the left, then the applied filters as chips,
 * then the button that offers the rest.
 *
 * DataTable renders this inside its toolbar row; list pages no longer mount it themselves.
 * It stays a standalone component because Staff/Form.vue drives a hand-rolled table that
 * needs the controls without the rest of DataTable, and it draws no band chrome of its own
 * so whoever mounts it decides what row it lives on.
 *
 * What changed and why. The bar used to render every filter a table declared as a
 * permanent control, which meant the print-job list opened with six dropdowns none of
 * which were being used, and three modules gave up and hand-rolled their own controls on
 * the page for the shapes the bar had no room for. The model here is Shopify's: nothing
 * is on the bar until it is either carrying a value or the operator asked for it, one
 * chip per filter, each with its own dropdown, each removable on its own.
 *
 * The server is still the source of truth for what exists and what is applied. This file
 * decides only which of the declared filters are visible, and it decides that from the
 * values the server sent back plus the handful the operator has just added and not yet
 * given a value to. Adding a filter to a module remains a declaration in its Table.
 *
 * Chips render in declaration order, not in the order they were added. The URL is the
 * shareable state, and two operators opening the same link have to see the same bar; a
 * local add-order would make the same URL look different to each of them.
 */
import { computed, ref, watch } from 'vue';
import ManageIcon from './ManageIcon.vue';
import FilterAddMenu from './FilterAddMenu.vue';
import FilterChip from './FilterChip.vue';
import { hasDeclaredDefault, isActive } from './filterValue.js';
import { useTableQuery } from './useTableQuery.js';

const props = defineProps({
  filters: { type: Array, default: () => [] },
  search: { type: String, default: '' },
  searchable: { type: Boolean, default: true },
});

const { setFilter, clearFilter, clearFilters, hasFilterParam, setSearch } = useTableQuery();

const term = ref(props.search);

/**
 * Filters the operator has put on the bar that are not narrowing anything yet.
 *
 * Deliberately client-side only. An added-but-unset filter must be genuinely absent from
 * the query string - parking a token there would be indistinguishable from a filter that
 * had been explicitly cleared, which is the one distinction the whole filter contract is
 * built around. The cost is that a half-added chip does not survive a reload, and it
 * should not: there is nothing to share.
 */
const staged = ref(new Set());

/** The chip to open as soon as it appears, so adding one lands the operator in it. */
const opening = ref(null);

const shown = computed(() =>
  props.filters.filter(
    (filter) => filter.pinned || isActive(filter) || staged.value.has(filter.key),
  ),
);

const available = computed(() => props.filters.filter((filter) => !shown.value.includes(filter)));

/**
 * Anything the "Clear all" button would actually undo: applied, defaulted, or sitting in
 * the URL as an explicit clear. The URL read is not reactive on its own, but `filters` is
 * replaced by every visit that could change it, so the computed re-runs when it matters.
 */
const removable = computed(() =>
  shown.value.filter(
    (filter) => isActive(filter) || hasDeclaredDefault(filter) || hasFilterParam(filter.key),
  ),
);

const add = (filter) => {
  // A boolean has no value to pick: it is on the moment it is on the bar. Applying it
  // straight away also means its chip arrives already carrying its meaning.
  if (filter.type === 'boolean') {
    setFilter(filter.key, true);

    return;
  }

  staged.value = new Set(staged.value).add(filter.key);
  opening.value = filter.key;
};

/**
 * Staging survives a value change, including a change back to nothing: picking "any
 * status" empties the filter but leaves the chip where the operator can pick again. Only
 * Remove takes a chip off.
 */
const update = (filter, value) => {
  staged.value = new Set(staged.value).add(filter.key);
  opening.value = null;

  setFilter(filter.key, value);
};

const remove = (filter) => {
  const next = new Set(staged.value);

  next.delete(filter.key);
  staged.value = next;

  if (opening.value === filter.key) {
    opening.value = null;
  }

  // A filter that was never applied has nothing in the URL to take out, so removing its
  // chip is not worth a request. `hasFilterParam` is the third case and the easy one to
  // miss: a filter the operator emptied while keeping its chip is sitting in the URL as
  // the cleared token, and the token has to go with the chip.
  if (isActive(filter) || hasDeclaredDefault(filter) || hasFilterParam(filter.key)) {
    clearFilter(filter.key, { defaulted: hasDeclaredDefault(filter) });
  }
};

const clearAll = () => {
  staged.value = new Set();
  opening.value = null;

  if (removable.value.length === 0) {
    return;
  }

  clearFilters(
    removable.value.map((filter) => ({ key: filter.key, defaulted: hasDeclaredDefault(filter) })),
  );
};

let debounce = null;

// The list polls on some modules, so the box follows the server rather than drifting away
// from what the rows are actually filtered by.
watch(
  () => props.search,
  (value) => {
    term.value = value;
  },
);

const onSearch = () => {
  window.clearTimeout(debounce);
  debounce = window.setTimeout(() => setSearch(term.value), 300);
};
</script>

<template>
  <!--
    A group inside a row, not a band of its own. It used to carry the band chrome - the
    bottom hairline, the surface, px-3 py-2, a min height - and that is precisely what made
    the list pages stack two full-height strips, because DataTable drew its own strip for
    the column toggle underneath. The band now belongs to DataTable's toolbar, which puts
    both on one row; this file lays out only its own controls.

    flex-1 takes whatever that row has left. The 12rem floor is what makes the row wrap the
    column toggle onto a line of its own instead of crushing the search box, and flex-wrap
    here is what lets the chips stack inside that width rather than push the page sideways.
  -->
  <div class="flex min-w-[12rem] flex-1 flex-wrap items-center gap-x-2 gap-y-1.5">
    <label v-if="searchable" class="flex h-7 min-w-0 flex-none items-center gap-1.5">
      <span class="sr-only">Search this list</span>

      <ManageIcon name="search" :size="13" class="shrink-0 text-fg-3" aria-hidden="true" />

      <input
        v-model="term"
        type="search"
        placeholder="Search"
        class="h-7 w-40 min-w-0 rounded border border-hairline bg-mg-surface-2 px-2 text-[12px] text-fg-1 outline-none transition-colors focus-visible:border-state-live/60 focus-visible:ring-1 focus-visible:ring-state-live/40 sm:w-52"
        @input="onSearch"
      />
    </label>

    <!-- A hairline between the search box and the filter side, only when both are there. -->
    <span
      v-if="searchable && filters.length > 0"
      class="hidden h-5 w-px shrink-0 bg-hairline sm:block"
      aria-hidden="true"
    />

    <FilterChip
      v-for="filter in shown"
      :key="filter.key"
      :filter="filter"
      :auto-open="opening === filter.key"
      @update="update(filter, $event)"
      @remove="remove(filter)"
    />

    <FilterAddMenu v-if="filters.length > 0" :available="available" @add="add" />

    <!--
      Sits next to the chips it clears, not pushed to the far right. The old ml-auto was
      aiming at the right edge of a bar this component owned end to end; that edge now
      belongs to the column toggle, and pushing Clear all against it would read as a pair of
      unrelated buttons and leave a gap between it and the filters it acts on.
    -->
    <button
      v-if="removable.length > 0"
      type="button"
      class="inline-flex h-7 shrink-0 items-center rounded px-2 text-[11px] leading-none text-fg-3 outline-none transition-colors hover:bg-mg-surface-3 hover:text-fg-1 focus-visible:ring-1 focus-visible:ring-state-live/40"
      @click="clearAll"
    >
      Clear all
    </button>
  </div>
</template>
