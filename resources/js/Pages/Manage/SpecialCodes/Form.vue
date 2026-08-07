<script setup>
/**
 * Create and edit a special code. One component for both: the field list is identical
 * and only the target route and the button copy differ.
 *
 * The three things the Filament form could not do are all here, and all for the same
 * reason: `class_name` was never `->live()`, so nothing downstream of it ever
 * re-evaluated while the modal was open (audit 33).
 *
 *  - `constructor_data` is editable. Its `disabled()` matcher compared the selected
 *    class against the literal 'EXAMPLE', which is not one of the options, so the only
 *    configurable knob on the action class was permanently locked (audit 32).
 *  - the example placeholder that matched the same dead literal is now reachable.
 *  - `catch_url` is computed as you type instead of once at render, which is why a
 *    create modal used to show the link for an empty code.
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  /** null on create. */
  specialCode: { type: Object, default: null },
  events: { type: Array, required: true },
  classOptions: { type: Array, required: true },
  /** `{scheme}://{fcea.domain}/`, the unchanging half of the catch link. */
  catchUrlBase: { type: String, required: true },
});

const editing = computed(() => Boolean(props.specialCode));

const form = useForm({
  event_id: props.specialCode?.event_id ?? '',
  class_name: props.specialCode?.class_name ?? '',
  constructor_data: props.specialCode?.constructor_data ?? '',
  code: props.specialCode?.code ?? '',
});

// Filament renders an empty option until one is picked; a required select keeps it too.
const eventOptions = computed(() => [{ value: '', label: 'Select an option' }, ...props.events]);
const classSelectOptions = computed(() => [{ value: '', label: 'Select an option' }, ...props.classOptions]);

const dataPlaceholder = computed(() =>
  form.class_name ? '{"amount": 100, "reason": "An Example"}' : '',
);

const catchUrl = computed(
  () => `${props.catchUrlBase}?code=${encodeURIComponent(form.code ?? '')}&auto`,
);

const submit = () => {
  if (editing.value) {
    form.put(route('manage.special-codes.update', props.specialCode.id));

    return;
  }

  form.post(route('manage.special-codes.store'));
};
</script>

<template>
  <Head :title="editing ? 'Edit special code' : 'New special code'" />

  <ManageLayout>
    <PageHeader :title="editing ? 'Edit special code' : 'New special code'" />

    <form class="flex flex-col gap-3 p-4" @submit.prevent="submit">
      <FormSection title="Special code">
        <FormField
          v-model="form.event_id"
          label="Event"
          type="select"
          :options="eventOptions"
          helper="Event in which the code can be used"
          :error="form.errors.event_id"
          required
        />

        <FormField
          v-model="form.class_name"
          label="Class"
          type="select"
          :options="classSelectOptions"
          helper="PHP class used for code handling"
          :error="form.errors.class_name"
        />

        <FormField
          v-model="form.constructor_data"
          label="Constructor Data"
          type="textarea"
          helper="Data to be passed to the constructor of the action class"
          :placeholder="dataPlaceholder"
          :error="form.errors.constructor_data"
        />

        <FormField
          v-model="form.code"
          label="Code"
          helper="E.g. ABC45"
          :error="form.errors.code"
          required
          narrow
          mono
        />

        <FormField label="Catch URL" helper="URL to catch the fursuiter with this code">
          <!--
            An input rather than plain text: it is read-only, not a static value, and an
            operator has to be able to select and copy it.
          -->
          <input
            :value="catchUrl"
            type="text"
            readonly
            class="h-8 w-full rounded border border-hairline bg-mg-surface-2 px-2 font-mono text-[13px] text-fg-2 outline-none"
          />
        </FormField>
      </FormSection>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="editing ? 'Save changes' : 'Create'"
      />
    </form>
  </ManageLayout>
</template>
