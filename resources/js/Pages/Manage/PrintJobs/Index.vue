<script setup>
/**
 * The print-job list, successor to the old print-job list's table and its ListPrintJobs page.
 *
 * The envelope arrives as top-level props rather than one nested object, because
 * useTableQuery reloads rows/meta/filters/sort/search as an Inertia partial visit and
 * partials are filtered by top-level key. DataTable still wants one object, so the props
 * are gathered back up here.
 *
 * All six server-declared filters ride in that envelope and DataTable puts them on its
 * toolbar. `printable_id` and `printable_type`,
 * the single free values the old panel rendered as TextInputs inside a filter form, used to be
 * drawn here as a second filter row because the bar had no free-value shape; they are
 * declared as Filter::number and Filter::text now and the bar renders both as chips.
 *
 * The title follows the printer filter: ListPrintJobs renamed itself `Print Jobs - {name}`
 * whenever the printer scope was on, and it still does now that the scope is a filter.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { usePagePoll } from '@/Components/Manage/usePagePoll.js';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  title: { type: String, default: 'Print Jobs' },
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

// the old print-job list polls this table every 5 seconds. Only the data props are
// reloaded: columns, filters and actions do not change under an operator's hands, and a
// poll must never be able to act on a job.
usePagePoll(5000, { only: ['rows', 'meta'] });
</script>

<template>
  <Head :title="title" />

  <ManageLayout>
    <PageHeader :title="title" :actions="pageActions" />

    <DataTable :table="table" searchable />
  </ManageLayout>
</template>
