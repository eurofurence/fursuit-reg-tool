<script setup>
/**
 * The badge list, successor to the old badge list's table and its ListBadges page.
 *
 * The envelope arrives as top-level props rather than one nested object, because
 * useTableQuery reloads rows/meta/filters/sort/search as an Inertia partial visit and
 * partials are filtered by top-level key. DataTable still wants one object, so the props
 * are gathered back up here.
 *
 * All four server-declared filters, the attendee range included, ride in that envelope and
 * DataTable puts them on its toolbar. The range used to be drawn here as a second filter
 * row because the bar had no shape for a pair of bounds; the bar renders every declared
 * type as its own chip now, so this page says nothing about filtering or paging.
 *
 * The printer picker on `Print Badges` is not hand-rolled here. The server declares the
 * select as a field on the action, and ActionButton renders it inside ManageDialog - the
 * panel's one dialog - together with the confirm heading and description. A second modal
 * living in this page would be a second focus trap, a second Escape handler and a second
 * place for the printer options to go stale.
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

/*
 * the old badge list polls this table every 5 seconds.
 *
 * `bulkActions` rides along with the data props, which the other lists do not do. The
 * only bulk action here is `printBadgeBulk`, and the printer select inside its dialog is
 * built from the printers that are active *now*; the old panel evaluated that option list once
 * when the table was built and never again, so a printer switched off mid-shift stayed on
 * offer until somebody reloaded the page. Reloading the action with the rows
 * resolves the options on the tick before the modal opens instead.
 *
 * The poll is a GET and stays one. It reads the printer list; it queues nothing. Printing
 * is `POST /admin/badges/{badge}/print` and `POST /admin/badges/bulk/print`, and only a
 * deliberate click reaches either.
 */
usePagePoll(5000, { only: ['rows', 'meta', 'bulkActions'] });
</script>

<template>
  <Head title="Badges" />

  <ManageLayout>
    <PageHeader title="Badges" :actions="pageActions" />

    <DataTable :table="table" searchable />
  </ManageLayout>
</template>
