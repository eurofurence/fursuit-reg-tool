<script setup>
/**
 * The SumUp reader list, successor to the old reader list's table and ListSumUpReaders.
 *
 * The envelope arrives as top-level props rather than one nested object, because
 * useTableQuery reloads rows/meta/filters/sort/search as an Inertia partial visit and
 * partials are filtered by top-level key. DataTable still wants one object, so the props
 * are gathered back up here.
 *
 * There is no filter set, no searchable column and no toggleable one: the old reader list
 * declares `->filters([ // ])` and marks nothing `->searchable()`. So DataTable is mounted
 * without `searchable` and draws no toolbar at all, which is the same nothing the page
 * showed before the toolbar moved inside it.
 *
 * The `Paring Code` cell is a mask that the server sent; the plaintext is not in these
 * props at all. It only appears in `revealed`, and only on the response to a `reveal`
 * request. Reloading the page loses it, which is the point.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import CopyableText from '@/Components/Manage/CopyableText.vue';
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
  /** { id, name, paring_code } for one response only, after a reveal. */
  revealed: { type: Object, default: null },
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
  <Head title="SumUp Readers" />

  <ManageLayout>
    <PageHeader title="SumUp Readers" :actions="pageActions" />

    <div v-if="revealed" class="mx-4 mt-4 rounded-md border border-state-warn/40 bg-mg-surface-1 p-3">
      <div class="flex items-center gap-2 text-[13px] font-medium text-fg-1">
        <ManageIcon name="key" :size="14" class="text-state-warn" />
        Pairing code for {{ revealed.name }}
      </div>

      <div class="mt-2 max-w-sm">
        <CopyableText :value="revealed.paring_code" masked />
      </div>

      <p class="mt-2 text-[11px] text-fg-3">
        Shown once. Reloading this page hides it again, and the request was written to the
        activity log.
      </p>
    </div>

    <DataTable :table="table" />
  </ManageLayout>
</template>
