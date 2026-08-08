<script setup>
/**
 * The TSE client list, successor to TseClientResource's table and ListTseClients.
 *
 * The envelope arrives as top-level props rather than one nested object, because
 * useTableQuery reloads rows/meta/filters/sort/search as an Inertia partial visit and
 * partials are filtered by top-level key. DataTable still wants one object, so the props
 * are gathered back up here.
 *
 * There is no filter set: TseClientResource declares an empty `->filters([])`. All three
 * columns are declared searchable on the server, and this page has nonetheless never
 * mounted the search box - it did not render FilterBar before the toolbar moved into
 * DataTable, and it does not pass `searchable` now. That is left exactly as it was rather
 * than quietly fixed here: this change is about where the controls live, not about which
 * ones a module offers, and turning search on for a fiscal read-only list is a decision
 * that belongs to whoever owns the parity checklist. No column is toggleable either, so
 * DataTable draws no toolbar at all.
 *
 * The page has no actions. `Create TSE Client` fabricated a client locally that Fiskaly
 * had never issued, and it is gone (rebuild-plan 2.10 #13); the row opens the record
 * rather than editing it, because the identity fields are read-only (2.10 #14). The note
 * under the header says where clients actually come from, since the panel no longer looks
 * like it can make one.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
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
  <Head title="TSE Clients" />

  <ManageLayout>
    <PageHeader
      title="TSE Clients"
      subtitle="The signing identities behind every fiscal transaction. Read-only."
    />

    <div class="mx-4 mt-4 rounded-md border border-hairline bg-mg-surface-1 p-3">
      <div class="flex items-center gap-2 text-[13px] font-medium text-fg-1">
        <ManageIcon name="shield-check" :size="14" class="text-state-ok" />
        Clients are issued by the TSE, not by this panel
      </div>

      <p class="mt-2 text-[11px] text-fg-3">
        A client's serial number ties every signed receipt back to the security module that
        signed it, so nothing here creates, edits or deletes one. Use
        <code class="font-mono">php artisan tse:update-state</code> and
        <code class="font-mono">php artisan tse:change-admin-pin</code>, which talk to Fiskaly.
      </p>
    </div>

    <DataTable :table="table" />
  </ManageLayout>
</template>
