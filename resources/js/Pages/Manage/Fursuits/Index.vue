<script setup>
/**
 * The fursuit list, successor to FursuitResource's table and its ListFursuits page.
 *
 * The envelope arrives as top-level props rather than one nested object, because
 * useTableQuery reloads rows/meta/filters/sort/search as an Inertia partial visit and
 * partials are filtered by top-level key. DataTable still wants one object, so the props
 * are gathered back up here.
 *
 * The status filter opens on Pending and always has: this list has never shown the full
 * set on first load (audit 135). Clearing it is an explicit request carrying the cleared
 * token, not an empty value, which is why FilterBar and App\Support\Manage\Table share
 * one constant for it.
 *
 * No header action: FursuitPolicy::create() is false, so the Filament create button was
 * hidden in practice and no create route exists.
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
  <Head title="Fursuits" />

  <ManageLayout>
    <PageHeader title="Fursuits" :actions="pageActions" />

    <DataTable :table="table" searchable />
  </ManageLayout>
</template>
