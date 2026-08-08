<script setup>
/**
 * Create and edit a print job, successor to CreatePrintJob and EditPrintJob.
 *
 * Three fields behave differently from the Filament form, and every one of them is here
 * because this record drives hardware.
 *
 *  - `status` is a transition picker, not a free select. It offers the state the job is in
 *    plus the edges PrintJobStatusEnum allows from there, and the server runs the model's
 *    own state handling rather than writing the column (rebuild-plan 2.10 #10). That is
 *    why the list is short: from `printed` there is nowhere left to go.
 *  - on a new job the status is fixed at Pending. There is nothing to transition from, and
 *    a create page that could fabricate a Printed card would say a card exists that nobody
 *    ever printed.
 *  - a badge job must name a batch. A batch-less badge job lands in the receipt-only
 *    unbatched lane and sits Pending forever (audit 89).
 *
 * Read-only fields render as text rather than greyed-out inputs, per FormField: a disabled
 * box invites clicking something that can never change.
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  /** Null on create. */
  job: { type: Object, default: null },
  printers: { type: Array, required: true },
  batches: { type: Array, default: () => [] },
  printableTypes: { type: Array, default: () => [] },
  typeOptions: { type: Array, required: true },
  /** The current status plus whatever it can transition to. Empty on create. */
  statusOptions: { type: Array, default: () => [] },
  /** Server-declared header actions, already visibility-filtered. */
  actions: { type: Array, default: () => [] },
});

const editing = computed(() => props.job !== null);

const form = useForm({
  printer_id: props.job?.printer_id ?? props.printers[0]?.value ?? null,
  // Empty rather than null: an <option> bound to null loses its value attribute and falls
  // back to its own text. ConvertEmptyStringsToNull turns it back into null server-side.
  print_batch_id: props.job?.print_batch_id ?? '',
  printable_type: props.printableTypes[0]?.value ?? null,
  printable_id: null,
  type: props.job?.type ?? props.typeOptions[0]?.value ?? null,
  status: props.job?.status ?? 'pending',
  priority: props.job?.priority ?? 0,
  retry_count: props.job?.retry_count ?? 0,
  error_message: props.job?.error_message ?? null,
  firmware_job_id: props.job?.firmware_job_id ?? null,
  firmware_job_uuid: props.job?.firmware_job_uuid ?? null,
});

const needsBatch = computed(() => form.type === 'badge');

const title = computed(() => (editing.value ? `Edit print job #${props.job.id}` : 'New print job'));

const submit = () => {
  if (editing.value) {
    form.put(route('manage.print-jobs.update', props.job.id));

    return;
  }

  form.post(route('manage.print-jobs.store'));
};
</script>

<template>
  <Head :title="title" />

  <ManageLayout>
    <PageHeader :title="title" :subtitle="job?.printable">
      <template #actions>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <form class="flex flex-col gap-3 p-4" @submit.prevent="submit">
      <FormSection title="Destination" description="Which printer runs this card, and as part of which run.">
        <FormField
          v-model="form.printer_id"
          label="Printer"
          type="select"
          :options="printers"
          :error="form.errors.printer_id"
          required
          narrow
        />

        <FormField
          v-model="form.type"
          label="Type"
          type="select"
          :options="typeOptions"
          :error="form.errors.type"
          required
          narrow
        />

        <FormField
          v-if="!editing"
          v-model="form.print_batch_id"
          label="Batch"
          type="select"
          :options="[{ value: '', label: 'No batch (receipts only)' }, ...batches]"
          helper="A badge job must belong to a batch. Nothing claims an unbatched badge job, so it would sit pending forever."
          :error="form.errors.print_batch_id"
          :required="needsBatch"
          narrow
        />

        <FormField v-else label="Batch" :model-value="job.print_batch_id" helper="The run a card belongs to is fixed when it is created." readonly />
        <FormField v-if="editing" label="Sequence" :model-value="job.sequence" readonly />
      </FormSection>

      <FormSection
        v-if="!editing"
        title="Printable"
        description="What this job is a print of. Both columns are required by the database, and which thing a card is a print of cannot be edited afterwards."
      >
        <FormField
          v-model="form.printable_type"
          label="Printable Type"
          type="select"
          :options="printableTypes"
          :error="form.errors.printable_type"
          required
          narrow
        />

        <FormField
          v-model="form.printable_id"
          label="Printable ID"
          type="number"
          :error="form.errors.printable_id"
          required
          narrow
        />
      </FormSection>

      <FormSection v-else title="Printable" description="What this job is a print of.">
        <FormField label="Printable" :model-value="job.printable" readonly />
      </FormSection>

      <FormSection
        title="Status"
        description="Status changes run through the job's own state handling, so a card marked printed releases its printer, moves its badge to ready for pickup and updates its batch."
      >
        <FormField
          v-if="editing"
          v-model="form.status"
          label="Status"
          type="select"
          :options="statusOptions"
          helper="Only the transitions this job can actually make. Marking a job failed pauses its batch, the same way a failure reported by the print agent does."
          :error="form.errors.status"
          required
          narrow
        />

        <FormField
          v-else
          label="Status"
          model-value="Pending"
          helper="A new job always starts pending. There is nothing to transition from, and no card has been printed yet."
          readonly
        />

        <FormField
          v-model="form.priority"
          label="Priority"
          type="number"
          :error="form.errors.priority"
          narrow
        />

        <FormField
          v-model="form.retry_count"
          label="Retry Count"
          type="number"
          :error="form.errors.retry_count"
          narrow
        />
      </FormSection>

      <FormSection title="Diagnostics" description="What the printer and the agent reported.">
        <FormField
          v-model="form.error_message"
          label="Error Message"
          type="textarea"
          :error="form.errors.error_message"
        />

        <FormField
          v-model="form.firmware_job_id"
          label="Printer job id"
          helper="Reported by the printer firmware over SNMP, which is what the agent matches a finished card against."
          :error="form.errors.firmware_job_id"
          mono
          narrow
        />

        <FormField
          v-model="form.firmware_job_uuid"
          label="Printer job UUID"
          :error="form.errors.firmware_job_uuid"
          mono
          narrow
        />
      </FormSection>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="editing ? 'Save changes' : 'Create print job'"
      />
    </form>
  </ManageLayout>
</template>
