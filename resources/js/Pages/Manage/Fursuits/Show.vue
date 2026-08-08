<script setup>
/**
 * The fursuit view page: the infolist, the approval workflow, and the activity log.
 *
 * Successor to ViewFursuit plus ActivitiesRelationManager. Three things read
 * differently from the Filament page and all three are decisions the plan made:
 *
 *  - Claiming is a button. `public $defaultAction = 'Claim'` mounted the Claim action on
 *    every page load, so opening a pending fursuit claimed it without any gesture
 *    (plan 2.10 #41). The page also says who holds the claim, which it never did.
 *  - Reject and Send Notification are rendered here rather than by ActionButton, because
 *    both have live forms: the reason picker fills the textarea as you choose, and the
 *    notification type shows its reason field immediately. The Filament Select behind
 *    the second was never ->live(), so the field only appeared on the next round-trip
 *    (audit 73). Everything about the two actions except the rendering still comes from
 *    the server declaration, so what the operator sees is what the tests assert.
 *  - The activity log is read-only (plan 2.10 #12). It arrives as an ordinary table
 *    envelope at the top level, which is unambiguous because it is the only table on the
 *    page, so sorting, searching and paging the log work the way they do everywhere else.
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
  fursuit: { type: Object, required: true },
  /** Server-declared header actions, already visibility-filtered. */
  actions: { type: Array, default: () => [] },
  /** The eight keyed rejection reasons, in order. */
  rejectReasons: { type: Array, default: () => [] },
  notificationTypes: { type: Array, default: () => [] },

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

/** The two actions this page renders itself. */
const CUSTOM = ['reject', 'send-notification'];

const named = (name) => props.actions.find((action) => action.name === name) ?? null;

const headerActions = computed(() => props.actions.filter((action) => !CUSTOM.includes(action.name)));

const rejectAction = computed(() => named('reject'));
const notifyAction = computed(() => named('send-notification'));

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

const claimLabel = computed(() => {
  if (props.fursuit.claim.mine) {
    return 'Claimed by you';
  }

  return props.fursuit.claim.claimed ? 'Claimed by another reviewer' : 'Not claimed';
});

const claimTone = computed(() => {
  if (props.fursuit.claim.mine) {
    return 'ok';
  }

  return props.fursuit.claim.claimed ? 'warn' : 'idle';
});

/* Reject. The picker only fills the textarea; only the textarea is stored and sent. */
const rejectOpen = ref(false);
const rejectForm = useForm({ reason: '', custom_reason: '' });

watch(
  () => rejectForm.reason,
  (key) => {
    const option = props.rejectReasons.find((reason) => reason.value === key);

    if (option) {
      rejectForm.custom_reason = option.label;
    }
  },
);

const submitReject = () => {
  rejectForm.post(rejectAction.value.url, {
    preserveScroll: true,
    onSuccess: () => {
      rejectOpen.value = false;
      rejectForm.reset();
    },
  });
};

/* Send notification. The reason field appears the moment the type is picked. */
const notifyOpen = ref(false);
const notifyForm = useForm({ notification_type: '', rejection_reason: '' });

const notifyIsRejection = computed(() => notifyForm.notification_type === 'rejected');

const submitNotify = () => {
  notifyForm.post(notifyAction.value.url, {
    preserveScroll: true,
    onSuccess: () => {
      notifyOpen.value = false;
      notifyForm.reset();
    },
  });
};

const control =
  'h-8 w-full rounded border border-hairline bg-mg-surface-2 px-2 text-[13px] text-fg-1 outline-none focus:border-state-live/50';

const textarea =
  'w-full rounded border border-hairline bg-mg-surface-2 px-2 py-1.5 text-[13px] text-fg-1 outline-none focus:border-state-live/50';
</script>

<template>
  <Head :title="fursuit.name ?? 'Fursuit'" />

  <ManageLayout>
    <PageHeader :title="fursuit.name ?? 'Fursuit'" :subtitle="fursuit.species">
      <template #actions>
        <ActionButton v-for="action in headerActions" :key="action.name" :action="action" />

        <button
          v-if="rejectAction"
          type="button"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-state-danger/35 px-2 text-[12px] font-medium text-state-danger transition-colors hover:bg-state-danger/12"
          @click="rejectOpen = true"
        >
          <ManageIcon :name="rejectAction.icon" />
          {{ rejectAction.label }}
        </button>

        <button
          v-if="notifyAction"
          type="button"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] font-medium text-fg-1 transition-colors hover:bg-mg-surface-3"
          @click="notifyOpen = true"
        >
          <ManageIcon :name="notifyAction.icon" />
          {{ notifyAction.label }}
        </button>
      </template>
    </PageHeader>

    <div class="flex flex-col gap-3 p-4">
      <!--
        The infolist: a twelve-column grid, image on three and everything else on nine,
        transcribed from FursuitResource::infolist().
      -->
      <section class="grid grid-cols-1 gap-3 md:grid-cols-12">
        <div class="md:col-span-3">
          <div class="flex items-center justify-center rounded border border-hairline bg-mg-surface-1 p-2">
            <img
              v-if="fursuit.image"
              :src="fursuit.image"
              :alt="fursuit.name ?? ''"
              class="w-full rounded object-contain"
            />
            <span v-else class="flex h-40 items-center text-[12px] text-fg-3">No image</span>
          </div>
        </div>

        <div class="flex flex-col gap-3 md:col-span-9">
          <div class="rounded border border-hairline bg-mg-surface-1 px-3 py-2">
            <div class="flex items-baseline justify-between gap-3">
              <span class="text-[12px] font-medium text-fg-2">Name</span>
              <span class="text-[11px] text-fg-3">Name of the fursuit on the Badge</span>
            </div>
            <p class="text-[17px] font-bold text-fg-1">{{ fursuit.name ?? '—' }}</p>
            <p class="text-[11px] text-fg-3">Should not contain profanities.</p>
          </div>

          <div class="rounded border border-hairline bg-mg-surface-1 px-3 py-2">
            <div class="flex items-baseline justify-between gap-3">
              <span class="text-[12px] font-medium text-fg-2">Species</span>
              <span class="text-[11px] text-fg-3">Name of the species on the Badge</span>
            </div>
            <p class="text-[17px] font-bold text-fg-1">{{ fursuit.species ?? '—' }}</p>
            <p class="text-[11px] text-fg-3">Should not contain profanities.</p>
          </div>

          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded border border-hairline bg-mg-surface-1 px-3 py-2">
              <span class="text-[12px] font-medium text-fg-2">Published</span>
              <p class="py-1">
                <ManageIcon
                  :name="fursuit.published ? 'circle-check' : 'circle-x'"
                  :size="22"
                  :class="fursuit.published ? 'inline text-state-ok' : 'inline text-fg-3'"
                />
              </p>
              <p class="text-[11px] text-fg-3">
                Publish your fursuit in our online gallery for everyone to see.
              </p>
            </div>

            <div class="rounded border border-hairline bg-mg-surface-1 px-3 py-2">
              <span class="text-[12px] font-medium text-fg-2">Catch em all</span>
              <p class="py-1">
                <ManageIcon
                  :name="fursuit.catch_em_all ? 'circle-check' : 'circle-x'"
                  :size="22"
                  :class="fursuit.catch_em_all ? 'inline text-state-ok' : 'inline text-fg-3'"
                />
              </p>
              <p class="text-[11px] text-fg-3">
                Participate in the convention game to be catchable by other attendees.
              </p>
            </div>
          </div>

          <div class="rounded border border-hairline bg-mg-surface-1 px-3 py-2">
            <div class="flex items-center justify-between gap-3">
              <span class="text-[12px] font-medium text-fg-2">Status</span>
              <span class="text-[11px] text-fg-3">Current status of the fursuit.</span>
            </div>
            <div class="flex items-center gap-2 pt-1">
              <StatusBadge :status="fursuit.status" />
              <!--
                Who holds the lock. The Filament page showed no indication of a claim at
                all, so a reviewer could only find out by pressing a button and seeing
                where it took them.
              -->
              <span
                class="text-[11px]"
                :class="{
                  'text-state-ok': claimTone === 'ok',
                  'text-state-warn': claimTone === 'warn',
                  'text-fg-3': claimTone === 'idle',
                }"
              >{{ claimLabel }}</span>
            </div>
          </div>
        </div>
      </section>

      <FormSection title="Activity" description="Read-only record of what happened to this fursuit.">
        <DataTable :table="activities" searchable />
      </FormSection>
    </div>

    <!-- Reject: bare requiresConfirmation copy plus the action's own form. -->
    <ManageDialog
      v-if="rejectAction"
      v-model:visible="rejectOpen"
      :header="rejectAction.confirm?.heading ?? rejectAction.label"
      width="32rem"
    >
      <p v-if="rejectAction.confirm?.description" class="text-[13px] text-fg-2">
        {{ rejectAction.confirm.description }}
      </p>

      <label class="flex flex-col gap-1">
        <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Reason</span>
        <select v-model="rejectForm.reason" :class="control">
          <option value="">Select an option</option>
          <option v-for="reason in rejectReasons" :key="reason.value" :value="reason.value">
            {{ reason.label }}
          </option>
        </select>
      </label>

      <label class="flex flex-col gap-1">
        <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">
          Reason Sent to the User!
        </span>
        <textarea v-model="rejectForm.custom_reason" rows="4" :class="textarea" required />
        <span v-if="rejectForm.errors.custom_reason" class="text-[11px] text-state-danger">
          {{ rejectForm.errors.custom_reason }}
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
          {{ rejectAction.confirm?.submit ?? 'Confirm' }}
        </button>
      </template>
    </ManageDialog>

    <!-- Send Notification: no confirmation, and a reason field that reacts immediately. -->
    <ManageDialog
      v-if="notifyAction"
      v-model:visible="notifyOpen"
      :header="notifyAction.label"
      width="32rem"
    >
      <label class="flex flex-col gap-1">
        <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Notification Type</span>
        <select v-model="notifyForm.notification_type" :class="control" required>
          <option value="">Select an option</option>
          <option v-for="type in notificationTypes" :key="type.value" :value="type.value">
            {{ type.label }}
          </option>
        </select>
        <span v-if="notifyForm.errors.notification_type" class="text-[11px] text-state-danger">
          {{ notifyForm.errors.notification_type }}
        </span>
      </label>

      <label v-if="notifyIsRejection" class="flex flex-col gap-1">
        <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">
          Rejection Reason (Required for Rejection)
        </span>
        <textarea v-model="notifyForm.rejection_reason" rows="4" :class="textarea" required />
        <span v-if="notifyForm.errors.rejection_reason" class="text-[11px] text-state-danger">
          {{ notifyForm.errors.rejection_reason }}
        </span>
      </label>

      <template #footer>
        <button
          type="button"
          class="h-8 rounded border border-hairline px-3 text-[13px] text-fg-2 transition-colors hover:bg-mg-surface-3"
          @click="notifyOpen = false"
        >
          Cancel
        </button>
        <button
          type="button"
          class="h-8 rounded bg-state-live px-3 text-[13px] font-medium text-mg-surface-0"
          :disabled="notifyForm.processing"
          @click="submitNotify"
        >
          Submit
        </button>
      </template>
    </ManageDialog>
  </ManageLayout>
</template>
