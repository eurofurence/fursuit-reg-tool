<script setup>
/**
 * The print-job view page, successor to ViewPrintJob.
 *
 * The old panel resource defined no infolist, so its view page fell back to rendering the
 * form schema disabled. The same fields are here as read-only rows, plus the five the
 * resource surfaced nowhere at all: the batch, the sequence in it, the printable, how the
 * job's completion is known and whether anybody has vouched for the card.
 *
 * Nothing on this page acts. The header carries EditAction and nothing else, exactly as
 * ViewPrintJob did, and retry stays a deliberate gesture on the list.
 */
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

defineProps({
  job: { type: Object, required: true },
  /** Server-declared header actions, already visibility-filtered. */
  actions: { type: Array, default: () => [] },
});
</script>

<template>
  <Head :title="`Print job #${job.id}`" />

  <ManageLayout>
    <PageHeader :title="`Print job #${job.id}`" :subtitle="job.printable">
      <template #actions>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <div class="flex flex-col gap-3 p-4">
      <FormSection title="Job" description="What is being printed, where, and how it is going.">
        <FormField label="Printer" :model-value="job.printer" readonly />

        <FormField label="Type">
          <StatusBadge :status="job.type" />
        </FormField>

        <FormField label="Status">
          <StatusBadge :status="job.status" />
        </FormField>

        <FormField label="Printable" :model-value="job.printable" readonly />
        <FormField label="Machine" :model-value="job.machine" readonly />
        <FormField label="Priority" :model-value="job.priority" readonly />
        <FormField label="Retry Count" :model-value="job.retry_count" readonly />
      </FormSection>

      <FormSection
        title="Run"
        description="The batch this card belongs to and where it sits in it. Neither is visible anywhere in the old panel."
      >
        <FormField label="Batch" :model-value="job.batch" readonly />
        <FormField label="Sequence" :model-value="job.sequence" readonly />

        <FormField label="Completion">
          <StatusBadge v-if="job.completion_source" :status="job.completion_source" />
          <span v-else class="flex h-8 items-center text-[13px] text-fg-3">Not recorded</span>
        </FormField>

        <FormField label="Verification">
          <StatusBadge :status="job.verified" />
        </FormField>
      </FormSection>

      <FormSection title="Diagnostics" description="What the printer and the agent reported.">
        <FormField label="Error Message" :model-value="job.error_message" readonly />
        <FormField label="Printer job id" :model-value="job.firmware_job_id" readonly mono />
        <FormField label="Printer job UUID" :model-value="job.firmware_job_uuid" readonly mono />
        <FormField label="Created" :model-value="job.created_at" readonly />
        <FormField label="Printed" :model-value="job.printed_at" readonly />
      </FormSection>
    </div>
  </ManageLayout>
</template>
