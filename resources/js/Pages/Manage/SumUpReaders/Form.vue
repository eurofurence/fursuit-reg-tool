<script setup>
/**
 * Create and edit for a SumUp reader, replacing CreateSumUpReader and EditSumUpReader.
 *
 * Two fields differ from the Filament schema and both are plan changes, not drift.
 *
 * `remote_id` is rendered as read-only text rather than a `readOnly()` input, on both
 * pages as Filament had it. Filament's attribute was a client-side guard over a field
 * that still round-tripped into a `$guarded = []` model, so a crafted POST rewrote the
 * SumUp-side binding (plan 2.10 #17). It is not in the form state here, so there is
 * nothing to submit.
 *
 * `paring_code` opens empty on edit. The stored code is never shipped to the browser
 * (plan 2.10 #16); the header's Reveal action is the only way to read it, and leaving the
 * field blank keeps it. On create it is required, exactly as Filament had it.
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import CopyableText from '@/Components/Manage/CopyableText.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  /** null on create. Never carries paring_code. */
  reader: { type: Object, default: null },
  /** { id, name, paring_code } for one response only, after a reveal. */
  revealed: { type: Object, default: null },
  /** Server-declared header actions: Reveal and Delete on edit, none on create. */
  actions: { type: Array, default: () => [] },
});

const editing = computed(() => Boolean(props.reader?.id));

const form = useForm({
  name: props.reader?.name ?? '',
  paring_code: '',
});

const submit = () => {
  if (editing.value) {
    form.put(route('manage.sumup-readers.update', props.reader.id));

    return;
  }

  form.post(route('manage.sumup-readers.store'));
};
</script>

<template>
  <Head :title="editing ? 'Edit sum up reader' : 'New sum up reader'" />

  <ManageLayout>
    <PageHeader
      :title="editing ? 'Edit sum up reader' : 'New sum up reader'"
      :subtitle="editing ? reader.name : null"
      :actions="actions"
    />

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

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex-1 space-y-3 p-4">
        <FormSection title="Sum Up Reader">
          <FormField
            v-model="form.name"
            label="Name"
            :error="form.errors.name"
            required
          />

          <!--
            On create as well as on edit. Filament rendered a readOnly() TextInput on both
            pages, empty on create because SumUp has not bound the reader yet; hiding it
            there would drop a field the audit records rather than change how it is
            written.
          -->
          <FormField
            :model-value="reader?.remote_id"
            label="Remote Id"
            readonly
            mono
            helper="Set by SumUp. Not editable here."
          />

          <FormField
            v-model="form.paring_code"
            label="Paring Code"
            type="password"
            mono
            :error="form.errors.paring_code"
            :required="!editing"
            :placeholder="editing ? 'Leave blank to keep the current code' : null"
            :helper="
              editing
                ? 'The stored code is never loaded into this form. Use Reveal to read it, or type a new one to replace it.'
                : null
            "
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
