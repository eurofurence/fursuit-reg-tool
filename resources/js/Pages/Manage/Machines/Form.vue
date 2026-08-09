<script setup>
/**
 * Create and edit for a machine, the successor to CreateMachine and EditMachine.
 *
 * The field order is the old machine list's flat schema: name, TSE client, SumUp reader,
 * should-discover-printers. `archived_at` is not a field here and never was; archiving is
 * its own action on the list.
 *
 * The Login Link header action is EditMachine's, with the credential it mints kept out of
 * the page payload. Nothing exists until the button is pressed: the endpoint answers with
 * a redirect carrying the URL in Inertia's flash bag, which is a top-level key on the page
 * object rather than a prop, so it never lands in the browser's history state and a back
 * navigation cannot put a live login link back on screen.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import CopyableText from '@/Components/Manage/CopyableText.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import ManageDialog from '@/Components/Manage/ManageDialog.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  /** null on create. */
  machine: { type: Object, default: null },
  sumupReaders: { type: Array, default: () => [] },
  /** Server-declared header actions; the Login Link on edit, nothing on create. */
  actions: { type: Array, default: () => [] },
});

const editing = computed(() => Boolean(props.machine?.id));

const form = useForm({
  name: props.machine?.name ?? '',
  sumup_reader_id: props.machine?.sumup_reader_id ?? '',
  // Unticked on create, which is what the old panel Checkbox did: it set no default, so
  // a new machine was saved with this off even though the column defaults to true. Kept
  // as it is rather than quietly changed, since the plan lists no fix for it.
  should_discover_printers: props.machine?.should_discover_printers ?? false,
});

const loginLink = ref(null);
const linkOpen = ref(false);

let stopListening = null;

onMounted(() => {
  stopListening = router.on('success', (event) => {
    const minted = event.detail.page?.flash?.machineLoginLink;

    if (minted && minted.machineId === props.machine?.id) {
      loginLink.value = minted;
      linkOpen.value = true;
    }
  });
});

onUnmounted(() => {
  stopListening?.();
  loginLink.value = null;
});

const expiry = computed(() => {
  if (!loginLink.value?.expiresAt) {
    return null;
  }

  return new Date(loginLink.value.expiresAt).toLocaleTimeString([], {
    hour: '2-digit',
    minute: '2-digit',
  });
});

const submit = () => {
  if (editing.value) {
    form.put(route('admin.machines.update', props.machine.id));

    return;
  }

  form.post(route('admin.machines.store'));
};
</script>

<template>
  <Head :title="editing ? 'Edit machine' : 'New machine'" />

  <ManageLayout>
    <PageHeader
      :title="editing ? 'Edit machine' : 'New machine'"
      :subtitle="editing ? machine.name : null"
      :actions="actions"
    />

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex-1 space-y-3 p-4">
        <FormSection title="Machine">
          <FormField
            v-model="form.name"
            label="Name"
            :error="form.errors.name"
            required
          />

          <FormField
            v-model="form.sumup_reader_id"
            label="SumUp Reader"
            type="select"
            :options="sumupReaders"
            :error="form.errors.sumup_reader_id"
          />

          <FormField
            v-model="form.should_discover_printers"
            label="Should discover printers"
            type="checkbox"
            :error="form.errors.should_discover_printers"
          />
        </FormSection>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="editing ? 'Save changes' : 'Create'"
      />
    </form>

    <ManageDialog v-model:visible="linkOpen" header="Login Link" width="32rem">
      <p class="text-[13px] text-fg-2">
        Anyone holding this link can log in as this machine. It stops working at
        {{ expiry }}, {{ loginLink?.minutes }} minutes after it was created.
      </p>

      <CopyableText :value="loginLink?.url" />
    </ManageDialog>
  </ManageLayout>
</template>
