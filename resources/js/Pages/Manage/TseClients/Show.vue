<script setup>
/**
 * The TSE client detail page. It has no Filament counterpart: the resource declared no
 * view page and no infolist, so the only way to look at a client was the edit form, and
 * that form is exactly what does not come across (rebuild-plan 2.10 #14).
 *
 * Everything on this page is read-only, and not by disabling inputs: the fields render as
 * text, so nothing on screen suggests a value that could be changed. A TSE client is the
 * identity a security module signs under, and its serial has to stay traceable from every
 * signed receipt back to the module for as long as the records are kept.
 *
 * The bound machine is the one thing the old screen could not answer at all.
 * `TseClient::machine()` exists but nothing surfaced it, so there was no way to see which
 * POS terminal a client was signing for (audit 4.12).
 *
 * The header carries no actions, on purpose. There is no edit, no delete and no register
 * or deregister button, because none of those is a local decision.
 */
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

defineProps({
  client: { type: Object, required: true },
});
</script>

<template>
  <Head :title="`TSE client ${client.remote_id}`" />

  <ManageLayout>
    <PageHeader :title="`TSE client ${client.remote_id}`" :subtitle="client.serial_number" />

    <div class="flex flex-col gap-3 p-4">
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
          helper="What the Fiskaly dashboard and the DSFinV-K export call this state. Changed with php artisan tse:update-state."
        />
      </FormSection>

      <FormSection
        title="Binding"
        description="Which POS machine signs through this client. The old panel surfaced this nowhere."
      >
        <FormField label="Machine" :model-value="client.machine" readonly />
        <FormField label="Created" :model-value="client.created_at" readonly />
        <FormField label="Updated" :model-value="client.updated_at" readonly />
      </FormSection>
    </div>
  </ManageLayout>
</template>
