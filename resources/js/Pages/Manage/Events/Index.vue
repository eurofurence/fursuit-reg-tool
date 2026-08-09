<script setup>
/**
 * The event list, successor to the old event list's table and its ManageEvents page.
 *
 * Rendered inside SettingsLayout, not ManageLayout: Events is a Settings pane rather than a
 * rail entry of its own (see routes/manage/events.php), so the settings submenu stays beside
 * it and the header reads "Settings / Events". The page-level actions the table declares go
 * into that layout's header slot, which is why there is no PageHeader here.
 *
 * The envelope arrives as top-level props rather than one nested object, because
 * useTableQuery reloads rows/meta/filters/sort/search as an Inertia partial visit and
 * partials are filtered by top-level key. DataTable still wants one object, so the props
 * are gathered back up here.
 *
 * DataTable is mounted without `searchable`: the old event list declares `->filters([ // ])`
 * and no column is searchable either, so a search box here would be one the server
 * ignores. The toolbar still appears, carrying the column toggle for the three toggleable
 * columns and nothing else. Users passes `searchable`; this table has nothing to put in
 * one.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import DataTable from '@/Components/Manage/DataTable.vue';

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
  <Head title="Events" />

  <SettingsLayout flush>
    <template #actions>
      <ActionButton v-for="action in pageActions" :key="action.name" :action="action" />
    </template>

    <DataTable :table="table" />
  </SettingsLayout>
</template>
