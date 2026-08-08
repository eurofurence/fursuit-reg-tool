<script setup>
/**
 * Create and edit a special code. One component for both: the field list is identical
 * and only the target route and the button copy differ.
 *
 * The three things the Filament form could not do are all here, and all for the same
 * reason: `class_name` was never `->live()`, so nothing downstream of it ever
 * re-evaluated while the modal was open (audit 33).
 *
 *  - the action's data is editable. Its `disabled()` matcher compared the selected class
 *    against the literal 'EXAMPLE', which is not one of the options, so the only
 *    configurable knob on the action class was permanently locked (audit 32).
 *  - the fields swap when the class changes, instead of a dead placeholder.
 *  - `catch_url` is computed as you type instead of once at render, which is why a
 *    create modal used to show the link for an empty code.
 *
 * There is no JSON textarea. `constructor_data` is assembled on the server from one input
 * per key the selected action class declares (`actionSchemas`, built from the class
 * itself), so this file holds no knowledge of what any action takes: it renders whichever
 * declaration the server ships for the selected class, exactly as the tables render
 * server-declared columns. Errors come back on `data.<field>` and land on that field.
 */
import { computed, ref, watch } from 'vue';
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
  /** class name => { label, description, fields[] }, including '' for no class. */
  actionSchemas: { type: Object, required: true },
  /** `{scheme}://{fcea.domain}/`, the unchanging half of the catch link. */
  catchUrlBase: { type: String, required: true },
});

const editing = computed(() => Boolean(props.specialCode));

/** The class and the values the record was loaded with, so switching away and back restores them. */
const storedClass = props.specialCode?.class_name ?? '';
const storedValues = { ...(props.specialCode?.data ?? {}) };

const schemaFor = (className) =>
  props.actionSchemas[className] ?? { label: null, description: null, fields: [] };

const form = useForm({
  event_id: props.specialCode?.event_id ?? '',
  class_name: storedClass,
  data: { ...storedValues },
  code: props.specialCode?.code ?? '',
});

const schema = computed(() => schemaFor(form.class_name));

/*
 * The fields are what the selected class declares, so the values follow it. A key the new
 * class also declares keeps what is typed; returning to the record's own class restores
 * what was stored; anything else starts at the declared default. Keys of the class being
 * left are dropped here and on the server, because they described the previous action.
 */
watch(
  () => form.class_name,
  (className) => {
    const source = className === storedClass ? storedValues : {};

    form.data = Object.fromEntries(
      schemaFor(className).fields.map((field) => {
        if (field.name in source) {
          return [field.name, source[field.name]];
        }

        return [field.name, field.name in form.data ? form.data[field.name] : field.default];
      }),
    );
  },
);

// Filament renders an empty option until one is picked; a required select keeps it too.
const eventOptions = computed(() => [{ value: '', label: 'Select an option' }, ...props.events]);
const classSelectOptions = computed(() => [{ value: '', label: 'Select an option' }, ...props.classOptions]);

/** True while the record names a class the panel no longer offers, which cannot be saved as is. */
const classUnavailable = computed(
  () => form.class_name !== '' && !(form.class_name in props.actionSchemas),
);

const showStoredData = ref(false);

const catchUrl = computed(
  () => `${props.catchUrlBase}?code=${encodeURIComponent(form.code ?? '')}&auto`,
);

const submit = () => {
  if (editing.value) {
    form.put(route('admin.special-codes.update', props.specialCode.id));

    return;
  }

  form.post(route('admin.special-codes.store'));
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

      <FormSection title="Action data" :description="schema.description">
        <p v-if="classUnavailable" class="py-2 text-[12px] text-state-danger">
          This code names {{ form.class_name }}, which the panel no longer offers. Its stored data is
          shown below and kept as it is; pick a class from the list before saving.
        </p>

        <FormField
          v-for="field in schema.fields"
          :key="field.name"
          v-model="form.data[field.name]"
          :label="field.label"
          :type="field.control"
          :options="field.options"
          :step="field.step"
          :helper="field.help"
          :required="field.required"
          :error="form.errors[`data.${field.name}`]"
        />

        <p v-if="!schema.fields.length && !classUnavailable" class="py-2 text-[12px] text-fg-3">
          Nothing to fill in for this class.
        </p>

        <!--
          The stored document, read-only. It is the only place an operator sees a key the
          current schema does not declare, e.g. a row written before this form existed.
          Those keys are written back unchanged as long as the class is not changed.
        -->
        <FormField
          v-if="specialCode?.unmanagedData"
          label="Unrecognised data"
          helper="Keys the selected class does not declare. They are kept on save unless you change the class."
        >
          <pre class="overflow-x-auto rounded border border-hairline bg-mg-surface-2 px-2 py-1.5 font-mono text-[12px] text-fg-2">{{ specialCode.unmanagedData }}</pre>
        </FormField>

        <FormField
          v-if="editing && specialCode?.storedData"
          label="Stored JSON"
          helper="What the database holds right now, before this form is saved."
        >
          <button
            v-if="!showStoredData"
            type="button"
            class="h-8 text-[12px] text-fg-3 underline underline-offset-2 hover:text-fg-1"
            @click="showStoredData = true"
          >
            Show
          </button>
          <pre
            v-else
            class="overflow-x-auto rounded border border-hairline bg-mg-surface-2 px-2 py-1.5 font-mono text-[12px] text-fg-2"
          >{{ specialCode.storedData }}</pre>
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
