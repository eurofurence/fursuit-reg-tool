<script setup>
/**
 * Special codes list.
 *
 * The envelope arrives as top-level props rather than one nested object, because
 * useTableQuery reloads rows/meta/filters/sort/search as an Inertia partial visit and
 * partials are filtered by top-level key. DataTable still wants one object, so the props
 * are gathered back up here.
 *
 * No filters and no search: the Filament table declared neither, and the only narrowing
 * that exists is the global event scope, which the strip already owns. So there is no
 * FilterBar here at all rather than an empty one.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import Pagination from '@/Components/Manage/Pagination.vue';

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
  <Head title="Special Codes" />

  <ManageLayout>
    <PageHeader title="Special Codes" :actions="pageActions" />

    <DataTable :table="table" />

    <Pagination :meta="meta" />
  </ManageLayout>
</template>
