<script setup>
/**
 * Create and edit for a printer, plus the condition panel of plan 2.10 #27.
 *
 * The field order is PrinterResource's flat schema. Two things behave differently and both
 * are in the plan.
 *
 * `default_paper_size` offers the sizes this printer has reported, and only those. The
 * Filament closure type-hinted a non-nullable `Printer $record`, so on the create page it
 * was handed null and threw a TypeError; nobody saw it because ListPrinters removed the
 * Create button while leaving the page and its route registered (plan 2.10 #7, audit
 * landmines 27 and 39). A printer that has never checked in has no sizes yet, so the
 * select is empty and says why.
 *
 * The condition block is read-only and edit-only. `condition`, `condition_message`,
 * `cards_remaining`, `cards_capacity` and `condition_reported_at` have existed since
 * 2026_08_05_100300 and have never appeared in admin, and PrinterConditionEnum::remedy()
 * - the sentence that tells staff what to actually do - was shown only in the POS.
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

const props = defineProps({
  /** null on create. */
  printer: { type: Object, default: null },
  /** [{ value, label }] from the machine relation. */
  machines: { type: Array, default: () => [] },
  /** [{ value, label }], the two PrintJobTypeEnum options. */
  types: { type: Array, default: () => [] },
  /** [{ value, label }] built from this printer's reported paper sizes. */
  paperSizes: { type: Array, default: () => [] },
  /** What the print agent last reported. null on create. */
  condition: { type: Object, default: null },
  /** Server-declared page actions: Clear error and Delete, on edit only. */
  actions: { type: Array, default: () => [] },
});

const editing = computed(() => Boolean(props.printer?.id));

const placeholder = { value: '', label: 'Select an option' };

const typeOptions = computed(() => [placeholder, ...props.types]);
const machineOptions = computed(() => [placeholder, ...props.machines]);
const paperSizeOptions = computed(() => [placeholder, ...props.paperSizes]);

/* What the agent last reported, as [{ name, detail }]. Empty on create. */
const reportedPaperSizes = computed(() => props.printer?.reportedPaperSizes ?? []);

const paperSizeHelper = computed(() =>
  props.paperSizes.length
    ? null
    : 'This printer has not reported any paper sizes yet. The print agent fills them in when it first checks in.',
);

const form = useForm({
  name: props.printer?.name ?? '',
  type: props.printer?.type ?? '',
  machine_id: props.printer?.machine_id ?? '',
  default_paper_size: props.printer?.default_paper_size ?? '',
  is_active: props.printer?.is_active ?? false,
});

const submit = () => {
  if (editing.value) {
    form.put(route('admin.printers.update', props.printer.id));

    return;
  }

  form.post(route('admin.printers.store'));
};
</script>

<template>
  <Head :title="editing ? 'Edit printer' : 'New printer'" />

  <ManageLayout>
    <PageHeader
      :title="editing ? 'Edit printer' : 'New printer'"
      :subtitle="editing ? printer.name : null"
      :actions="actions"
    />

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex-1 space-y-3 p-4">
        <FormSection title="Printer">
          <FormField
            v-model="form.name"
            label="Name"
            :error="form.errors.name"
            required
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
            v-model="form.machine_id"
            label="Machine"
            type="select"
            :options="machineOptions"
            :error="form.errors.machine_id"
            required
          />

          <FormField
            v-model="form.default_paper_size"
            label="Default Paper Size"
            type="select"
            :options="paperSizeOptions"
            :helper="paperSizeHelper"
            :error="form.errors.default_paper_size"
          />

          <!--
            Read-only, and rows rather than the JSON document Filament printed into a
            disabled Textarea: it is a short list of named sizes, which is a table, and the
            braces were never information. The print agent owns this reading, so the panel
            shows it and never writes it; PrinterRequest does not accept the field either,
            so a crafted post cannot overwrite the hardware's own answer.
          -->
          <FormField
            label="Paper Sizes"
            helper="Reported by the print agent when the printer checks in. Not editable here."
          >
            <ul v-if="reportedPaperSizes.length" class="space-y-1 py-1">
              <li
                v-for="(size, index) in reportedPaperSizes"
                :key="index"
                class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 rounded border border-hairline bg-mg-surface-2 px-2 py-1.5"
              >
                <span class="text-[13px] text-fg-1">{{ size.name }}</span>
                <span v-if="size.detail" class="text-[12px] text-fg-3">{{ size.detail }}</span>
              </li>
            </ul>
            <span v-else class="flex h-8 items-center text-[13px] text-fg-3">
              Nothing reported yet.
            </span>
          </FormField>

          <FormField
            v-model="form.is_active"
            label="Is Active"
            type="checkbox"
            :error="form.errors.is_active"
            helper="An inactive printer is not offered for new print jobs."
          />
        </FormSection>

        <FormSection
          v-if="condition"
          title="Reported condition"
          description="What the print agent last read off the hardware over SNMP. Read-only."
          :columns="2"
        >
          <FormField label="Status">
            <span class="flex h-8 items-center">
              <StatusBadge :status="condition.status" />
            </span>
          </FormField>

          <FormField label="Condition">
            <span class="flex h-8 items-center">
              <StatusBadge :status="condition.condition" />
            </span>
          </FormField>

          <FormField label="Detail" :model-value="condition.message" readonly />

          <FormField label="What to do" :model-value="condition.remedy" readonly />

          <FormField label="Cards" :model-value="condition.cards" readonly />

          <FormField
            label="Condition reported"
            :model-value="condition.reportedAt?.display"
            readonly
          />

          <FormField
            label="Last update"
            :model-value="condition.lastStateUpdate?.display"
            readonly
          />

          <FormField
            label="Handling machine"
            :model-value="condition.handlingMachineName"
            readonly
          />

          <FormField
            label="Last error"
            :model-value="condition.lastErrorMessage"
            readonly
          />
        </FormSection>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="editing ? 'Save changes' : 'Create'"
      />
    </form>
  </ManageLayout>
</template>
