<script setup>
/**
 * Edit a fursuit. There is no create counterpart: FursuitPolicy::create() returns false
 * and no create route exists.
 *
 * Three fields differ from the old panel form, all of them plan decisions:
 *
 *  - `status` is a picker over the transitions the state machine allows from where this
 *    record stands, not a free TextInput writing straight through the cast. Choosing
 *    Rejected asks for the reason that is mailed to the owner, because that is an
 *    argument the transition takes.
 *  - `approved_at` and `rejected_at` are gone. They were hand-editable and could
 *    contradict `status`; the transitions stamp them now.
 *  - `event_id` is a relation select rather than a numeric TextInput.
 *
 * The image uploads through POST /admin/uploads, which stores it on s3 with private
 * visibility. The old panel FileUpload had no ->disk() while the table, the infolist and
 * DbService all read s3.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

const props = defineProps({
  fursuit: { type: Object, required: true },
  actions: { type: Array, default: () => [] },
  users: { type: Array, required: true },
  species: { type: Array, required: true },
  events: { type: Array, required: true },
  /** [{ value, label }] of the states reachable from the current one. */
  transitions: { type: Array, default: () => [] },
  uploadPurpose: { type: String, required: true },
});

const page = usePage();

const form = useForm({
  user_id: props.fursuit.user_id ?? '',
  species_id: props.fursuit.species_id ?? '',
  event_id: props.fursuit.event_id ?? '',
  name: props.fursuit.name ?? '',
  image: props.fursuit.image ?? '',
  published: Boolean(props.fursuit.published),
  catch_em_all: Boolean(props.fursuit.catch_em_all),
  // The picker rests on the current state, which means "leave the status alone".
  status: props.fursuit.status,
  rejection_reason: '',
});

const preview = ref(props.fursuit.imageUrl ?? null);
const uploading = ref(false);

const statusOptions = computed(() => [
  { value: props.fursuit.status, label: `${props.fursuit.statusLabel.label} (no change)` },
  ...props.transitions,
]);

const rejecting = computed(() => form.status === 'rejected' && props.fursuit.status !== 'rejected');

/*
 * The upload is its own request and answers with a redirect carrying the stored path in
 * Inertia's flash bag, so the form field ends up holding an ordinary string and the save
 * stays a plain PUT. `flash` is a top-level key on the page object rather than a prop,
 * which is why it is read off the visit's own page rather than from usePage().props.
 */
const upload = (event) => {
  const file = event.target.files?.[0];

  if (!file) {
    return;
  }

  uploading.value = true;

  router.post(
    route('admin.uploads.store'),
    { purpose: props.uploadPurpose, file },
    {
      forceFormData: true,
      preserveScroll: true,
      preserveState: true,
      only: [],
      onFinish: () => {
        uploading.value = false;
        event.target.value = '';
      },
      onSuccess: (visit) => {
        const stored = visit?.flash?.upload ?? page.props.flash?.upload;

        if (stored?.purpose === props.uploadPurpose) {
          form.image = stored.path;
          preview.value = stored.url;
        }
      },
    },
  );
};

const submit = () => form.put(route('admin.fursuits.update', props.fursuit.id));
</script>

<template>
  <Head title="Edit fursuit" />

  <ManageLayout>
    <PageHeader title="Edit fursuit" :subtitle="fursuit.name" :actions="actions" />

    <form class="flex flex-col gap-3 p-4" @submit.prevent="submit">
      <FormSection title="Fursuit">
        <FormField
          v-model="form.user_id"
          label="User"
          type="select"
          :options="users"
          :error="form.errors.user_id"
          required
        />

        <FormField
          v-model="form.species_id"
          label="Species"
          type="select"
          :options="species"
          :error="form.errors.species_id"
          required
        />

        <FormField
          v-model="form.event_id"
          label="Event"
          type="select"
          :options="events"
          :error="form.errors.event_id"
          required
        />

        <FormField
          v-model="form.name"
          label="Name"
          :error="form.errors.name"
          required
        />

        <FormField label="Image" :error="form.errors.image">
          <div class="flex items-start gap-3">
            <img
              v-if="preview"
              :src="preview"
              alt=""
              class="h-20 w-20 rounded border border-hairline object-cover"
            />
            <div class="flex flex-col gap-1">
              <input
                type="file"
                accept="image/jpeg,image/png"
                class="text-[12px] text-fg-2 file:mr-2 file:h-7 file:rounded file:border file:border-hairline file:bg-mg-surface-2 file:px-2 file:text-[12px] file:text-fg-1"
                :disabled="uploading"
                @change="upload"
              />
              <span class="text-[11px] text-fg-3">
                {{ uploading ? 'Uploading…' : 'Stored privately on s3.' }}
              </span>
            </div>
          </div>
        </FormField>

        <FormField
          v-model="form.published"
          label="Published"
          type="toggle"
          :error="form.errors.published"
          required
        />

        <FormField
          v-model="form.catch_em_all"
          label="Catch em all"
          type="toggle"
          :error="form.errors.catch_em_all"
          required
        />
      </FormSection>

      <FormSection
        title="Status"
        description="Status changes run through the state machine, so timestamps, the activity log and the owner's notification all follow."
      >
        <FormField label="Current">
          <StatusBadge :status="fursuit.statusLabel" />
        </FormField>

        <FormField
          v-model="form.status"
          label="Change to"
          type="select"
          :options="statusOptions"
          :error="form.errors.status"
          helper="Only the transitions this fursuit's current status allows are offered."
        />

        <FormField
          v-if="rejecting"
          v-model="form.rejection_reason"
          label="Reason Sent to the User!"
          type="textarea"
          helper="Sent to the owner with the rejection mail."
          :error="form.errors.rejection_reason"
          required
        />
      </FormSection>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        submit-label="Save changes"
      />
    </form>
  </ManageLayout>
</template>
