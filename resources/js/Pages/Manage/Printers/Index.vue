<script setup>
/**
 * The printer list, successor to the old printer list's table and its ListPrinters page.
 *
 * The envelope arrives as top-level props rather than one nested object, because
 * useTableQuery reloads rows/meta/filters/sort/search as an Inertia partial visit and
 * partials are filtered by top-level key. DataTable still wants one object, so the props
 * are gathered back up here.
 *
 * There is no filter set: the old printer list declares `->filters([ // ])`. DataTable still
 * gets `searchable`, so its toolbar renders with the search box alone, which the resource
 * hid at table level with `->searchable(false)` and which the name column needs.
 */
import { computed } from 'vue';
import { Head, usePoll } from '@inertiajs/vue3';
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
 * The one screen that reports a jam had no poll at all, so staff watched a frozen table
 * while a printer sat stopped. Fifteen seconds, and only the
 * data props: columns, filters and actions do not change under an operator's hands, and
 * a GET that reloads rows can never fire the inline is_active toggle - that is a POST,
 * and only a click makes it.
 */
usePoll(15000, { only: ['rows', 'meta'] });
</script>

<template>
  <Head title="Printers" />

  <ManageLayout>
    <PageHeader title="Printers" :actions="pageActions" />

    <DataTable :table="table" searchable />
  </ManageLayout>
</template>
