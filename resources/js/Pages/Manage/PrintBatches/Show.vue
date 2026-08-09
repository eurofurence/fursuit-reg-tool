<script setup>
/**
 * The batch detail page, successor to ViewPrintBatch and its PrintJobsRelationManager.
 *
 * Two things it does that the old panel page did not.
 *
 * The run controls are in the header. ViewPrintBatch::getHeaderActions() returns [] on
 * purpose, so pause, resume and cancel were reachable only from the list row: an operator
 * who opened a batch to find out which card jammed had to navigate back to stop the run
 *. They are the same server-declared actions the row carries.
 *
 * The page polls. Staff watch this screen during a live run and it never refreshed itself
 *. Four props are reloaded and no more: the cards (`rows`, `meta`) plus
 * `batch` and `actions`, because those two carry the run's status, its counters and the
 * `disabledReason` on each control. Without them a card failing mid-run - which pauses the
 * batch from `PrintJob::markFailed()` - repainted the card as Failed while the header still
 * read Printing and Resume stayed disabled with a reason computed at page load, so the one
 * screen that exists to recover a halted run could not resume it without a manual reload.
 *
 * Reloading them is still a read: `show()` authorizes, loads three relations and derives
 * the same two props it derives on a full visit. Nothing on the poll path writes.
 *
 * The card envelope arrives as top-level props for the same reason the index's does:
 * useTableQuery reloads rows/meta/filters/sort/search as a partial visit, and Inertia
 * filters partials by top-level key.
 */
import { computed } from 'vue';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

const props = defineProps({
  batch: { type: Object, required: true },
  /** Pause, Resume, Cancel and Retry, already policy-filtered and carrying their own copy. */
  actions: { type: Array, default: () => [] },

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

const cards = computed(() => ({
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

/** The infolist placeholders, which are per-entry rather than one shared dash. */
const or = (value, placeholder) => (value === null || value === undefined || value === '' ? placeholder : value);

usePoll(10000, { only: ['batch', 'actions', 'rows', 'meta'] });
</script>

<template>
  <Head :title="batch.name ?? `Batch #${batch.id}`" />

  <ManageLayout>
    <PageHeader :title="batch.name ?? `Batch #${batch.id}`" :subtitle="batch.status?.label">
      <template #actions>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <div class="flex flex-col gap-3 p-4">
      <FormSection title="Batch" :columns="3">
        <FormField label="Name" :model-value="batch.name" readonly />

        <FormField label="Status">
          <StatusBadge :status="batch.status" />
        </FormField>

        <FormField label="Printer" :model-value="or(batch.printer, 'Unassigned')" readonly />
        <FormField label="Event" :model-value="or(batch.event, 'None')" readonly />
        <FormField label="Built by" :model-value="or(batch.createdBy, 'System')" readonly />
        <FormField label="Pause reason" :model-value="or(batch.pauseReason, 'None')" readonly />

        <!-- Only where there is one: a run whose preparation failed and the run that was
             queued in its place are two rows, and each is only readable next to the other. -->
        <FormField v-if="batch.retryOf" label="Retry of">
          <Link :href="batch.retryOf.url" class="flex h-8 items-center text-[13px] text-state-live underline">
            Batch #{{ batch.retryOf.id }}
          </Link>
        </FormField>

        <FormField v-if="batch.retries.length" label="Retried as">
          <span class="flex h-8 items-center gap-3 text-[13px]">
            <Link
              v-for="retry in batch.retries"
              :key="retry.id"
              :href="retry.url"
              class="text-state-live underline"
            >Batch #{{ retry.id }}</Link>
          </span>
        </FormField>
      </FormSection>

      <FormSection title="Progress" :columns="4">
        <FormField label="Cards" :model-value="batch.progress.total" readonly />

        <FormField label="Printed">
          <span class="flex h-8 items-center text-[13px] text-state-ok tabular-nums">{{ batch.progress.printed }}</span>
        </FormField>

        <FormField label="Verified">
          <span class="flex h-8 items-center text-[13px] text-state-ok tabular-nums">{{ batch.progress.verified }}</span>
        </FormField>

        <FormField label="Failed">
          <span
            class="flex h-8 items-center text-[13px] tabular-nums"
            :class="batch.progress.failed > 0 ? 'text-state-danger' : 'text-fg-1'"
          >{{ batch.progress.failed }}</span>
        </FormField>
      </FormSection>

      <!-- The one section the old panel opens collapsed. -->
      <FormSection title="Timing" :columns="3" collapsible collapsed>
        <FormField label="Created" :model-value="batch.timing.created" readonly />
        <FormField label="Started" :model-value="or(batch.timing.started, 'Not started')" readonly />
        <FormField label="Completed" :model-value="or(batch.timing.completed, 'Not finished')" readonly />
      </FormSection>
    </div>

    <!-- PrintJobsRelationManager, title override `Cards`: the cards in this batch, in the
         order they print. -->
    <h2 class="border-t border-hairline px-4 pt-3 text-[12px] font-semibold uppercase tracking-wide text-fg-1">
      Cards
    </h2>

    <DataTable :table="cards" searchable />
  </ManageLayout>
</template>
