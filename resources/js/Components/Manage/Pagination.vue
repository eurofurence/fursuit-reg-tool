<script setup>
/**
 * The list footer: the range summary, the per-page select, and the page stepper.
 *
 * DataTable renders this under its own table, so a list page passes `meta` once and gets
 * toolbar, table and footer together. It stays a standalone component because three pages
 * page a table DataTable does not draw: the RFID tag list inside Staff/Form.vue is
 * hand-rolled markup, and it has to be able to mount the footer on its own.
 *
 * Why the page buttons lost their borders. They were size-6 bordered boxes separated by
 * gap-1, which at 4px apart put two hairlines almost on top of each other and read as one
 * fused strip rather than as five pages. Borders are the wrong device at this size: the
 * only page whose boundary matters is the current one, and it already has a fill and a
 * colour. So the buttons are borderless with a hover surface, sized to leave real air
 * between the digits, and the border budget is spent on the one control that needs an
 * affordance because it accepts input rather than a click.
 *
 * That is the rule the footer is now consistent about, and it is the toolbar's rule too:
 * inputs are bordered, buttons are not. The per-page select is bordered exactly like the
 * toolbar's search box - same height, same surface, same focus ring - so the two bands
 * bracketing the table are one idea seen twice.
 */
import { computed } from 'vue';
import ManageIcon from './ManageIcon.vue';
import { useTableQuery } from './useTableQuery.js';

const props = defineProps({
  meta: { type: Object, required: true },
});

const { setPage, setPerPage } = useTableQuery();

// A short window around the current page, so long lists do not grow a wall of links.
const pages = computed(() => {
  const { page, lastPage } = props.meta;
  const from = Math.max(1, page - 2);
  const to = Math.min(lastPage, from + 4);

  return Array.from({ length: to - from + 1 }, (_, index) => from + index);
});
</script>

<template>
  <!--
    py-2 with a min height rather than a fixed h-10, matching the toolbar: the footer grows
    by a whole row if the stepper wraps under the summary on a narrow window instead of
    handing the page a horizontal scrollbar.
  -->
  <div
    class="flex min-h-11 flex-wrap items-center gap-x-4 gap-y-2 border-t border-hairline px-3 py-2 text-[12px] text-fg-2"
  >
    <span class="tabular-nums">
      <template v-if="meta.total">{{ meta.from }}–{{ meta.to }} of {{ meta.total }}</template>
      <template v-else>0 results</template>
    </span>

    <label class="flex items-center gap-2">
      <span class="text-fg-3">per page</span>
      <select
        :value="meta.perPage"
        class="h-7 rounded border border-hairline bg-mg-surface-2 px-2 text-[12px] text-fg-1 outline-none transition-colors focus-visible:border-state-live/60 focus-visible:ring-1 focus-visible:ring-state-live/40"
        @change="setPerPage(Number($event.target.value))"
      >
        <option v-for="option in meta.perPageOptions" :key="option" :value="option">{{ option }}</option>
      </select>
    </label>

    <!--
      ml-auto only once the row has not wrapped; a wrapped stepper starts at the left of its
      own line, which is what auto margins on a flex-wrap row already do.
    -->
    <div v-if="meta.lastPage > 1" class="ml-auto flex items-center gap-0.5">
      <button
        type="button"
        class="inline-flex size-7 items-center justify-center rounded text-fg-2 outline-none transition-colors hover:bg-mg-surface-3 hover:text-fg-1 focus-visible:ring-1 focus-visible:ring-state-live/40 disabled:pointer-events-none disabled:opacity-30"
        :disabled="meta.page <= 1"
        aria-label="Previous page"
        @click="setPage(meta.page - 1)"
      >
        <ManageIcon name="chevron-left" :size="13" />
      </button>

      <!--
        aria-current is what tells a screen reader which of five identical-looking number
        buttons is the page being viewed. The fill says it to everyone else.
      -->
      <button
        v-for="page in pages"
        :key="page"
        type="button"
        class="inline-flex h-7 min-w-7 items-center justify-center rounded px-1.5 tabular-nums outline-none transition-colors focus-visible:ring-1 focus-visible:ring-state-live/40"
        :class="page === meta.page
          ? 'bg-state-live/15 font-medium text-state-live'
          : 'text-fg-2 hover:bg-mg-surface-3 hover:text-fg-1'"
        :aria-current="page === meta.page ? 'page' : undefined"
        @click="setPage(page)"
      >
        {{ page }}
      </button>

      <button
        type="button"
        class="inline-flex size-7 items-center justify-center rounded text-fg-2 outline-none transition-colors hover:bg-mg-surface-3 hover:text-fg-1 focus-visible:ring-1 focus-visible:ring-state-live/40 disabled:pointer-events-none disabled:opacity-30"
        :disabled="meta.page >= meta.lastPage"
        aria-label="Next page"
        @click="setPage(meta.page + 1)"
      >
        <ManageIcon name="chevron-right" :size="13" />
      </button>
    </div>
  </div>
</template>
