<script setup>
/**
 * Create and edit a special code. One component for both: the field list is identical
 * and only the target route and the button copy differ.
 *
 * The three things the old panel form could not do are all here, and all for the same
 * reason: `class_name` was never `->live()`, so nothing downstream of it ever
 * re-evaluated while the modal was open.
 *
 *  - the action's data is editable. Its `disabled()` matcher compared the selected class
 *    against the literal 'EXAMPLE', which is not one of the options, so the only
 *    configurable knob on the action class was permanently locked.
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
import { computed, ref, watch } from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import ManageLayout from "@/Layouts/ManageLayout.vue";
import FormActions from "@/Components/Manage/FormActions.vue";
import FormField from "@/Components/Manage/FormField.vue";
import FormSection from "@/Components/Manage/FormSection.vue";
import PageHeader from "@/Components/Manage/PageHeader.vue";

const props = defineProps({
    /** null on create. */
    specialCode: { type: Object, default: null },
    events: { type: Array, required: true },
    manageEventId: { type: Number, default: null },
    typeOptions: { type: Array, required: true },
    /** type value => { label, description, fields[] }, including '' for no type. */
    actionSchemas: { type: Object, required: true },
    /** `{scheme}://{fcea.domain}/`, the unchanging half of the catch link. */
    catchUrlBase: { type: String, required: true },
});

const editing = computed(() => Boolean(props.specialCode));

/** The values the record was loaded with, so switching away and back restores them. */
const storedValues = { ...(props.specialCode?.data ?? {}) };

const schemaForType = (typeValue) =>
    props.actionSchemas[typeValue] ?? {
        label: null,
        description: null,
        fields: [],
    };

const actionData = ref({ ...storedValues });

const form = useForm({
    event_id: props.specialCode?.event_id ?? props.manageEventId ?? "",
    type: props.specialCode?.type ?? "",
    code: props.specialCode?.code ?? "",
});

const schema = computed(() => schemaForType(form.type));

/*
 * The fields are what the selected class declares, so the values follow it. A key the new
 * class also declares keeps what is typed; returning to the record's own class restores
 * what was stored; anything else starts at the declared default. Keys of the class being
 * left are dropped here and on the server, because they described the previous action.
 */
watch(
    () => form.type,
    (type) => {
        const schema = schemaForType(type);

        actionData.value = Object.fromEntries(
            schema.fields.map((field) => {
                if (field.name in storedValues) {
                    return [field.name, storedValues[field.name]];
                }

                return [
                    field.name,
                    field.name in actionData.value
                        ? actionData.value[field.name]
                        : field.default,
                ];
            }),
        );
    },
    { immediate: true },
);

// the old panel renders an empty option until one is picked; a required select keeps it too.
const eventOptions = computed(() => [
    { value: "", label: "Select an option" },
    ...props.events,
]);
const typeSelectOptions = computed(() => [
    { value: "", label: "Select an option" },
    ...props.typeOptions,
]);

const showStoredData = ref(false);

const catchUrl = computed(
    () =>
        `${props.catchUrlBase}?code=${encodeURIComponent(form.code ?? "")}&auto`,
);

const submit = () => {
    const submitForm = (method) =>
        form
            .transform((data) => ({ ...data, data: actionData.value }))
            [
                method
            ](method === "put" ? route("admin.special-codes.update", props.specialCode.id) : route("admin.special-codes.store"));

    if (editing.value) {
        submitForm("put");

        return;
    }

    submitForm("post");
};
</script>

<template>
    <Head :title="editing ? 'Edit special code' : 'New special code'" />

    <ManageLayout>
        <PageHeader
            :title="editing ? 'Edit special code' : 'New special code'"
        />

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
                    v-model="form.type"
                    label="Type"
                    type="select"
                    :options="typeSelectOptions"
                    helper="Type used for code handling"
                    :error="form.errors.type"
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

                <FormField
                    label="Catch URL"
                    helper="URL to catch the fursuiter with this code"
                >
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
                <FormField
                    v-for="field in schema.fields"
                    :key="field.name"
                    v-model="actionData[field.name]"
                    :label="field.label"
                    :type="field.control"
                    :options="field.options"
                    :step="field.step"
                    :helper="field.help"
                    :required="field.required"
                    :error="form.errors[`data.${field.name}`]"
                />

                <p
                    v-if="!schema.fields.length"
                    class="py-2 text-[12px] text-fg-3"
                >
                    Nothing to fill in for this type.
                </p>

                <!--
          The stored document, read-only. It is the only place an operator sees a key the
          current schema does not declare, e.g. a row written before this form existed.
          Those keys are written back unchanged as long as the class is not changed.
        -->
                <FormField
                    v-if="specialCode?.unmanagedData"
                    label="Unrecognised data"
                    helper="Keys the selected type does not declare. They are kept on save unless you change the type."
                >
                    <pre
                        class="overflow-x-auto rounded border border-hairline bg-mg-surface-2 px-2 py-1.5 font-mono text-[12px] text-fg-2"
                        >{{ specialCode.unmanagedData }}</pre
                    >
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
                        >{{ specialCode.storedData }}</pre
                    >
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
