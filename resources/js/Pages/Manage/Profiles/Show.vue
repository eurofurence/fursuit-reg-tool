<script setup>
/**
 * One Catch-Em-All profile, the three verdicts, and the log of what happened to it.
 *
 * Successor to the old panel's ViewUserProfile plus its ActivitiesRelationManager, and it
 * is both the record page and the review surface: a profile is four short fields, so
 * there is nothing a separate queue page would show that this one does not.
 *
 * Two things worth knowing:
 *
 *  - The claim is real. Opening the page takes it (five minutes, renewed on every load)
 *    and the verdicts are refused without it, so two reviewers cannot decide the same
 *    profile a second apart. When somebody else holds it the buttons are disabled with
 *    the reason on them rather than hidden.
 *  - Reject is rendered here rather than through ActionButton, because picking a canned
 *    reason has to fill the box the moment it is picked - and the box stays editable, so
 *    a reviewer can add a sentence before sending it.
 */
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import ManageDialog from '@/Components/Manage/ManageDialog.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

const props = defineProps({
  profile: { type: Object, required: true },
  /** Server-declared, already filtered by state and by who holds the claim. */
  actions: { type: Array, default: () => [] },
  rejectionReasons: { type: Array, default: () => [] },
  queue: { type: Object, required: true },

  // The activity log envelope.
  name: { type: String, required: true },
  rows: { type: Array, required: true },
  columns: { type: Array, required: true },
  hiddenColumns: { type: Array, default: () => [] },
  filters: { type: Array, default: () => [] },
  sort: { type: Object, default: null },
  search: { type: String, default: '' },
  meta: { type: Object, required: true },
  bulkActions: { type: Array, default: () => [] },
  pageActions: { type: Array, default: () => [] },
});

/** Rendered by this page rather than by ActionButton: its form reacts as it is filled. */
const CUSTOM = ['reject'];

const named = (name) => props.actions.find((action) => action.name === name) ?? null;

const headerActions = computed(() => props.actions.filter((action) => !CUSTOM.includes(action.name)));

const rejectAction = computed(() => named('reject'));

const activities = computed(() => ({
  name: props.name,
  rows: props.rows,
  columns: props.columns,
  hiddenColumns: props.hiddenColumns,
  filters: props.filters,
  sort: props.sort,
  search: props.search,
  meta: props.meta,
  bulkActions: props.bulkActions,
  pageActions: props.pageActions,
}));

const changedAt = computed(() => (props.profile.updatedAt
  ? new Date(props.profile.updatedAt).toLocaleString()
  : null));

/* Reject. The picker writes into the box; the box stays editable. */
const rejectOpen = ref(false);
const rejectForm = useForm({ reason: '' });
const premade = ref('');

watch(premade, (value) => {
  if (value !== '') {
    rejectForm.reason = value;
  }
});

const submitReject = () => {
  rejectForm.post(rejectAction.value.url, {
    preserveScroll: true,
    onSuccess: () => {
      rejectOpen.value = false;
      rejectForm.reset();
      premade.value = '';
    },
  });
};

const control =
  'h-8 w-full rounded border border-hairline bg-mg-surface-2 px-2 text-[13px] text-fg-1 outline-none focus:border-state-live/50';

const textarea =
  'w-full rounded border border-hairline bg-mg-surface-2 px-2 py-1.5 text-[13px] text-fg-1 outline-none focus:border-state-live/50';
</script>

<template>
  <Head :title="profile.user ?? 'Profile'" />

  <ManageLayout>
    <PageHeader :title="profile.user ?? 'Profile'" subtitle="Catch-Em-All profile">
      <template #actions>
        <ActionButton v-for="action in headerActions" :key="action.name" :action="action" />

        <button
          v-if="rejectAction"
          type="button"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-state-danger/40 px-2 text-[12px] font-medium text-state-danger transition-colors hover:bg-state-danger/10 disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="Boolean(rejectAction.disabledReason)"
          :title="rejectAction.disabledReason ?? rejectAction.label"
          @click="rejectOpen = true"
        >
          <ManageIcon :name="rejectAction.icon" />
          {{ rejectAction.label }}
        </button>
      </template>
    </PageHeader>

    <div class="flex flex-col gap-3 p-4">
      <!--
        Somebody else has this profile open. Their claim expires on its own after five
        minutes, so this says what to do rather than offering a way to take it: reload
        then, and the page claims it.
      -->
      <div
        v-if="profile.claim.takenByOther"
        class="flex items-center gap-2 rounded border border-state-warn/40 bg-state-warn/10 px-3 py-2 text-[12px] text-state-warn"
      >
        <ManageIcon name="users" />
        <span>
          Another reviewer is working on this profile. Their claim expires after five
          minutes; reload then to take it over.
        </span>
      </div>

      <div class="flex items-center gap-2 rounded border border-hairline bg-mg-surface-1 px-3 py-2 text-[12px] text-fg-2">
        <ManageIcon name="hourglass" :size="14" />
        <span>{{ queue.remaining }} profile{{ queue.remaining === 1 ? '' : 's' }} waiting for a verdict.</span>
        <a :href="profile.publicUrl" target="_blank" rel="noopener" class="ml-auto text-state-live underline">
          Open the public page
        </a>
      </div>

      <section class="grid grid-cols-1 gap-3 md:grid-cols-12">
        <div class="md:col-span-3">
          <div class="flex flex-col items-center gap-2 rounded border border-hairline bg-mg-surface-1 p-3">
            <img
              v-if="profile.avatar"
              :src="profile.avatar"
              :alt="profile.user ?? ''"
              class="h-32 w-32 rounded-full object-cover"
            />
            <span v-else class="flex h-32 w-32 items-center justify-center rounded-full bg-mg-surface-2 text-[12px] text-fg-3">
              No avatar
            </span>
            <!--
              The avatar is part of the verdict, not decoration: it is mirrored from the
              identity provider, and a changed one sends the profile back here.
            -->
            <p class="text-center text-[11px] text-fg-3">
              Mirrored from the identity provider. A changed avatar sends the profile back to pending.
            </p>
          </div>
        </div>

        <div class="flex flex-col gap-3 md:col-span-9">
          <div class="rounded border border-hairline bg-mg-surface-1 px-3 py-2">
            <div class="flex items-center justify-between gap-3">
              <span class="text-[12px] font-medium text-fg-2">Status</span>
              <span class="text-[11px] text-fg-3">Approving publishes the description and every link at once.</span>
            </div>
            <div class="flex items-center gap-2 pt-1">
              <StatusBadge :status="profile.status" />
              <span v-if="changedAt" class="text-[11px] text-fg-3">Last changed {{ changedAt }}</span>
            </div>
          </div>

          <div
            v-if="profile.rejectionReason"
            class="rounded border border-state-danger/40 bg-state-danger/10 px-3 py-2"
          >
            <div class="flex items-center gap-2">
              <ManageIcon name="circle-x" :size="16" class="text-state-danger" />
              <span class="text-[12px] font-medium text-state-danger">Rejection reason</span>
            </div>
            <p class="pt-1 text-[12px] text-fg-2">{{ profile.rejectionReason }}</p>
            <p class="text-[11px] text-fg-3">Shown to the profile owner.</p>
          </div>

          <div class="rounded border border-hairline bg-mg-surface-1 px-3 py-2">
            <div class="flex items-baseline justify-between gap-3">
              <span class="text-[12px] font-medium text-fg-2">Description</span>
              <span class="text-[11px] text-fg-3">Shown publicly on the profile</span>
            </div>
            <p class="whitespace-pre-line text-[13px] text-fg-1">{{ profile.description ?? '—' }}</p>
            <p class="text-[11px] text-fg-3">Should not contain profanities.</p>
          </div>

          <div class="rounded border border-hairline bg-mg-surface-1 px-3 py-2">
            <div class="flex items-baseline justify-between gap-3">
              <span class="text-[12px] font-medium text-fg-2">Links</span>
              <span class="text-[11px] text-fg-3">Open each one and check where it leads</span>
            </div>
            <ul v-if="profile.links.length" class="flex flex-col gap-1 pt-1">
              <!--
                target=_blank with rel=noopener: these are attendee-submitted URLs, and the
                whole point of the page is that a reviewer clicks them.
              -->
              <li v-for="link in profile.links" :key="link" class="flex items-center gap-2">
                <ManageIcon name="link" :size="14" class="text-fg-3" />
                <a
                  :href="link"
                  target="_blank"
                  rel="noopener nofollow"
                  class="break-all text-[13px] text-state-live underline"
                >{{ link }}</a>
              </li>
            </ul>
            <p v-else class="text-[13px] text-fg-3">No links</p>
          </div>
        </div>
      </section>

      <FormSection title="Activity" description="Read-only record of what happened to this profile.">
        <DataTable :table="activities" searchable />
      </FormSection>
    </div>

    <!-- Reject: the picker fills the box, and the box is still editable afterwards. -->
    <ManageDialog
      v-if="rejectAction"
      v-model:visible="rejectOpen"
      header="Reject profile"
      width="32rem"
    >
      <p class="text-[13px] text-fg-2">
        The description and links stay hidden from other attendees.
      </p>

      <label class="flex flex-col gap-1">
        <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Common reasons</span>
        <select v-model="premade" :class="control">
          <option value="">Write your own</option>
          <option v-for="reason in rejectionReasons" :key="reason" :value="reason">
            {{ reason }}
          </option>
        </select>
      </label>

      <label class="flex flex-col gap-1">
        <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Reason shown to the user</span>
        <textarea v-model="rejectForm.reason" rows="4" :class="textarea" maxlength="255" required />
        <span v-if="rejectForm.errors.reason" class="text-[11px] text-state-danger">
          {{ rejectForm.errors.reason }}
        </span>
      </label>

      <template #footer>
        <button
          type="button"
          class="h-8 rounded border border-hairline px-3 text-[13px] text-fg-2 transition-colors hover:bg-mg-surface-3"
          @click="rejectOpen = false"
        >
          Cancel
        </button>
        <button
          type="button"
          class="h-8 rounded bg-state-danger px-3 text-[13px] font-medium text-mg-surface-0"
          :disabled="rejectForm.processing"
          @click="submitReject"
        >
          Reject
        </button>
      </template>
    </ManageDialog>
  </ManageLayout>
</template>
