<script setup>
/**
 * The batch list, successor to the old batch list's table and its ListPrintBatches page.
 *
 * Batch oversight for staff who are not standing at the printer: everything here is read
 * only apart from the three run controls, which arrive as server-declared row actions with
 * their confirm copy already resolved. There is no create button, because a batch can only
 * come from the badge list's print action, which is the only path that can freeze the print
 * order and lock the badges together.
 *
 * The envelope arrives as top-level props rather than one nested object, because
 * useTableQuery reloads rows/meta/filters/sort/search as an Inertia partial visit and
 * partials are filtered by top-level key. DataTable wants one object, so the props are
 * gathered back up here.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { usePagePoll } from '@/Components/Manage/usePagePoll.js';
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

// the old batch list polls this table every 10 seconds. Only the data props
// are reloaded: columns, filters and actions do not change under an operator's hands, and
// a poll must never be able to pause, resume or cancel a run.
usePagePoll(10000, { only: ['rows', 'meta'] });
</script>

<template>
  <Head title="Print Batches" />

  <ManageLayout>
    <PageHeader title="Print Batches" :actions="pageActions" />

    <DataTable :table="table" searchable />
  </ManageLayout>
</template>
