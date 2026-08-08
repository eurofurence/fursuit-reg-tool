<script setup>
/**
 * The user list, successor to the old user list's table and its ManageUsers page.
 *
 * Rendered inside SettingsLayout, not ManageLayout: Users is a Settings pane rather than a
 * rail group of its own (see routes/manage/users.php), so the settings submenu stays beside
 * it and the header reads "Settings / Users". The page-level actions the table declares go
 * into that layout's header slot, which is why there is no PageHeader here.
 *
 * The envelope arrives as top-level props rather than one nested object, because
 * useTableQuery reloads rows/meta/filters/sort/search as an Inertia partial visit and
 * partials are filtered by top-level key. DataTable still wants one object, so the props
 * are gathered back up here.
 *
 * There is no filter set: the old user list declares `->filters([ // ])`. DataTable still gets
 * `searchable`, so its toolbar renders with the search box alone, which is what the three
 * searchable columns need.
 *
 * `tabs` is the one addition, and it is the same kind of thing as `columns`: the server
 * declares the preset views (All, Admins, Reviewers) and DataTable draws the strip above
 * its toolbar. Nothing on this page decides anything about them.
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
  tabs: { type: Array, default: () => [] },
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
  tabs: props.tabs,
  filters: props.filters,
  sort: props.sort,
  search: props.search,
  meta: props.meta,
  bulkActions: props.bulkActions,
  pageActions: props.pageActions,
}));
</script>

<template>
  <Head title="Users" />

  <SettingsLayout flush>
    <template #actions>
      <ActionButton v-for="action in pageActions" :key="action.name" :action="action" />
    </template>

    <DataTable :table="table" searchable />
  </SettingsLayout>
</template>
