<script setup>
/**
 * The checkout detail page, successor to ViewCheckout and its ItemsRelationManager.
 *
 * the old checkout list defined no infolist, so its view page rendered the form schema with
 * every field disabled. The same four sections are here as read-only rows, in the same
 * order, with the same section titles and the same two collapsed by default.
 *
 * Nothing on this page can be edited, and there is no form to submit: a checkout is a
 * German fiscal record, and CheckoutPolicy refuses create, update and delete for everybody.
 * The header carries the two actions ViewCheckout carried and nothing else. Download
 * Receipt is a link; Print Receipt is a POST behind a confirm, and it creates a print job
 * rather than touching the checkout.
 *
 * The money figures are euros formatted once on the server from integer cents, by the same
 * formatter the list column uses. The old panel page rendered raw cents behind a euro prefix
 * while its own table column divided by 100, so one fiscal record read two different ways
 * on one screen.
 *
 * The TSE block shows the columns that exist. `tse_signature` never did: the migration
 * created `tse_start_signature` and `tse_end_signature`, so the old panel field was
 * permanently blank.
 *
 * The items table is the ordinary list envelope, arriving as top-level props for the same
 * partial-visit reason every list does, so searching and paging it uses the shared code
 * path. It is read-only: no filters, no row actions, no bulk actions, no header actions.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

const props = defineProps({
  checkout: { type: Object, required: true },
  /** Server-declared header actions, already visibility-filtered. */
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

const items = computed(() => ({
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
  <Head :title="`Checkout #${checkout.id}`" />

  <ManageLayout>
    <PageHeader :title="`Checkout #${checkout.id}`" :subtitle="checkout.user">
      <template #actions>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <div class="flex flex-col gap-3 p-4">
      <FormSection title="Checkout Information" :columns="2">
        <FormField label="Remote ID" :model-value="checkout.remote_id" readonly mono />
        <FormField label="Customer" :model-value="checkout.user" readonly />
        <FormField label="Cashier" :model-value="checkout.cashier" readonly />
        <FormField label="Machine" :model-value="checkout.machine" readonly />

        <FormField label="Status">
          <StatusBadge :status="checkout.status" />
        </FormField>

        <FormField label="Payment Method">
          <StatusBadge :status="checkout.payment_method" />
        </FormField>
      </FormSection>

      <FormSection title="Financial Details" :columns="3">
        <FormField label="Subtotal" :model-value="checkout.subtotal" readonly />
        <FormField label="Tax" :model-value="checkout.tax" readonly />
        <FormField label="Total" :model-value="checkout.total" readonly />
      </FormSection>

      <FormSection
        title="TSE Information"
        description="The signatures the Fiskaly security module wrote for this sale. Only the two timestamps were visible in the old panel."
        :columns="2"
        collapsible
        collapsed
      >
        <FormField label="TSE Start" :model-value="checkout.tse_start_timestamp" readonly />
        <FormField label="TSE End" :model-value="checkout.tse_end_timestamp" readonly />
        <FormField label="TSE Serial Number" :model-value="checkout.tse_serial_number" readonly mono />
        <FormField label="TSE Transaction Number" :model-value="checkout.tse_transaction_number" readonly mono />

        <FormField label="TSE Start Signature">
          <span class="block py-1.5 font-mono text-[12px] break-all text-fg-1">
            {{ checkout.tse_start_signature ?? '—' }}
          </span>
        </FormField>

        <FormField label="TSE End Signature">
          <span class="block py-1.5 font-mono text-[12px] break-all text-fg-1">
            {{ checkout.tse_end_signature ?? '—' }}
          </span>
        </FormField>
      </FormSection>

      <FormSection title="Timestamps" :columns="2" collapsible collapsed>
        <FormField label="Created At" :model-value="checkout.created_at" readonly />
        <FormField label="Updated At" :model-value="checkout.updated_at" readonly />
      </FormSection>

      <section class="rounded border border-hairline bg-mg-surface-1">
        <header class="flex items-center gap-2 border-b border-hairline px-3 py-2">
          <h2 class="text-[12px] font-semibold uppercase tracking-wide text-fg-1">Checkout Items</h2>
        </header>

        <!--
          `name` is the one searchable column the relation manager declared, and the table
          declares no filters, so the toolbar is the search box on its own.
        -->
        <DataTable :table="items" searchable />
      </section>
    </div>
  </ManageLayout>
</template>
