<script setup>
/**
 * The TSE client detail page. It has no the old panel counterpart: the resource declared no
 * view page and no infolist, so the only way to look at a client was the edit form, and
 * that form is exactly what does not come across.
 *
 * Everything on this page is read-only, and not by disabling inputs: the fields render as
 * text, so nothing on screen suggests a value that could be changed. A TSE client is the
 * identity a security module signs under, and its serial has to stay traceable from every
 * signed receipt back to the module for as long as the records are kept.
 *
 * The bound machine is the one thing the old screen could not answer at all.
 * `TseClient::machine()` exists but nothing surfaced it, so there was no way to see which
 * POS terminal a client was signing for.
 *
 * The header carries the two lifecycle actions and nothing else. There is no edit and no
 * delete: the identity is what receipts were signed under, and only `state` may move.
 */
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

defineProps({
  client: { type: Object, required: true },
  /** Register or Deregister, whichever this client is not already. */
  headerActions: { type: Array, default: () => [] },
});
</script>

<template>
  <Head :title="`TSE client ${client.remote_id}`" />

  <ManageLayout>
    <PageHeader
      :title="`TSE client ${client.remote_id}`"
      :subtitle="client.serial_number"
      :actions="headerActions"
    />

    <div class="flex flex-col gap-3 p-4">
      <div class="rounded-md border border-state-warn/40 bg-state-warn/10 p-3">
        <div class="flex items-center gap-2 text-[13px] font-medium text-fg-1">
          <ManageIcon name="triangle-alert" :size="14" class="text-state-warn" />
          Watch out: anything you do here can cost real money
        </div>

        <p class="mt-2 text-[11px] text-fg-3">
          TSE clients are billed by Fiskaly. Creating, registering or deregistering one is a paid
          operation against the live security module, not a local record change.
        </p>
      </div>

      <FormSection
        title="Identity"
        description="Issued by the TSE. These values are what past checkouts were signed under, so nothing in this panel writes them."
      >
        <FormField label="Remote ID" :model-value="client.remote_id" readonly mono />
        <FormField
          label="Serial Number"
          :model-value="client.serial_number"
          readonly
          mono
          helper="Ties every signed receipt back to the security module that signed it."
        />

        <FormField label="State">
          <StatusBadge :status="client.state" />
        </FormField>

        <FormField
          label="Stored value"
          :model-value="client.state_value"
          readonly
          mono
          helper="What the Fiskaly dashboard and the DSFinV-K export call this state. Changed with Register and Deregister above, which call Fiskaly."
        />
      </FormSection>

      <FormSection
        title="Binding"
        description="Left over from when a machine named its own client. Every till now signs under whichever client is registered, so this is history, not configuration."
      >
        <FormField label="Machine" :model-value="client.machine" readonly />
        <FormField label="Created" :model-value="client.created_at" readonly />
        <FormField label="Updated" :model-value="client.updated_at" readonly />
      </FormSection>
    </div>
  </ManageLayout>
</template>
