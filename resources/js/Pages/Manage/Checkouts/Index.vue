<script setup>
/**
 * The checkout list, successor to the old checkout list's table and its ListCheckouts page.
 *
 * The envelope arrives as top-level props rather than one nested object, because
 * useTableQuery reloads rows/meta/filters/sort/search as an Inertia partial visit and
 * partials are filtered by top-level key. DataTable still wants one object, so the props
 * are gathered back up here.
 *
 * All five server-declared filters ride in that envelope and DataTable puts them on its
 * toolbar. `created_from` and `created_until`, the custom date form the old panel rendered as two
 * DatePickers inside one filter, used to be drawn here as a second filter row because the
 * bar had no date shape; they are declared as Filter::date now and the bar renders each as a
 * chip with its own date picker.
 *
 * The Sum summariser rides in `meta`, so it is reloaded with the rows it totals rather than
 * freezing at whatever the first paint showed. On a fiscal screen a stale total under a
 * filtered set would be worse than no total. It goes in DataTable's `summary` slot rather
 * than between two components this page mounts, which is the same slot for the same reason:
 * it has to land under the rows and above the pager, and the pager is DataTable's now.
 *
 * There is no create button and no bulk bar, and neither is a styling choice: checkouts are
 * created by the POS only, and CheckoutPolicy refuses create, update and delete outright.
 * The list does not poll either, matching the resource.
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
  <Head title="Checkouts" />

  <ManageLayout>
    <PageHeader title="Checkouts" :actions="pageActions" />

    <DataTable :table="table" searchable>
      <!-- the old panel's Sum summariser row, under the column it totals. -->
      <template #summary>
        <div
          v-if="meta.summary"
          class="flex h-9 items-center justify-end gap-3 border-t border-hairline bg-mg-surface-2 px-3 text-[12px]"
        >
          <span class="text-[11px] font-medium uppercase tracking-wide text-fg-3">{{ meta.summary.label }}</span>
          <span class="tabular-nums text-fg-1">{{ meta.summary.value }}</span>
        </div>
      </template>
    </DataTable>
  </ManageLayout>
</template>
