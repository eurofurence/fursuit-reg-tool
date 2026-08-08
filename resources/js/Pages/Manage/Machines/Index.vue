<script setup>
/**
 * The machine list, successor to the old machine list's table and its ListMachines page.
 *
 * The envelope arrives as top-level props rather than one nested object, because
 * useTableQuery reloads rows/meta/filters/sort/search as an Inertia partial visit and
 * partials are filtered by top-level key. DataTable still wants one object, so the props
 * are gathered back up here.
 *
 * The archived filter opens blank, which means Active machines. Nothing scopes archived
 * machines at query level, so that blank branch is the only thing keeping
 * retired tills out of the list; the server applies it whether the filter is unset or
 * explicitly cleared.
 *
 * The login link is not here. It is minted on the edit page, by an explicit action, so
 * this list can be polled or left open without a credential ever being generated.
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
  <Head title="Machines" />

  <ManageLayout>
    <PageHeader title="Machines" :actions="pageActions" />

    <DataTable :table="table" searchable />
  </ManageLayout>
</template>
