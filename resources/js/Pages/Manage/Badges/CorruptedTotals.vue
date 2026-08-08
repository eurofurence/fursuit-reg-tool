<script setup>
/**
 * The corrupted-total report (rebuild-plan 2.10 #3).
 *
 * Read-only, and deliberately so: it says which badge totals the old Filament edit form
 * already damaged before the money fixes went in. Repairing them follows
 * FreeBadgeRepairService's pattern, with a preview and an activity entry, on the DB
 * Service page in phase 9.
 *
 * An empty table here is the good outcome, so the page says as much rather than leaving
 * an operator wondering whether the check ran.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  name: { type: String, required: true },
  rows: { type: Array, required: true },
  columns: { type: Array, required: true },
  hiddenColumns: { type: Array, default: () => [] },
  filters: { type: Array, default: () => [] },
  sort: { type: Object, default: null },
  search: { type: String, default: '' },
  meta: { type: Object, required: true },
  bulkActions: { type: Array, default: () => [] },
  pageActions: { type: Array, default: () => [] },
});

const table = computed(() => ({
  name: props.name,
  rows: props.rows,
  columns: props.columns,
  hiddenColumns: props.hiddenColumns,
  filters: props.filters,
  sort: props.sort,
  search: props.search,
  meta: props.meta,
  bulkActions: props.bulkActions,
  pageActions: props.pageActions,
}));
</script>

<template>
  <Head title="Badge total check" />

  <ManageLayout>
    <PageHeader
      title="Badge total check"
      subtitle="Badges whose stored total does not equal subtotal + tax"
    />

    <p class="border-b border-hairline bg-mg-surface-1 px-4 py-2 text-[12px] text-fg-2">
      Every badge written by the ordering pipeline satisfies total = subtotal + tax. A row here was
      written by something else, and the only other writer was the old admin edit form, which stored
      a formatted euro figure in a cents column. Nothing on this page changes any data.
    </p>

    <p v-if="!meta.total" class="px-4 py-6 text-[13px] text-state-ok">
      No badge totals are out of step. Nothing to repair.
    </p>

    <DataTable v-else :table="table" />
  </ManageLayout>
</template>
