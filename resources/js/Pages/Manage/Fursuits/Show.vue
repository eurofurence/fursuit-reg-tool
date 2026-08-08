<script setup>
/**
 * The fursuit record page: the infolist, the activity log, and no verdicts.
 *
 * Successor to ViewFursuit plus ActivitiesRelationManager, and deliberately not a review surface.
 * Every verdict lives in the queue at /admin/fursuits/review: this page used to offer Approve,
 * Reject, Block from gallery, Lift gallery block, Approve (Rejected) and Next Fursuit as well, which
 * meant two screens could hand down the same decision with different copy, different confirm
 * dialogs and - because the queue carries the undo window and the presence banner - different
 * safety. A record page is for reading a record; "Review in queue" is the way to act on it.
 *
 * What is left, and why:
 *
 *  - Send Notification is rendered here rather than by ActionButton because its reason field has to
 *    react the moment a type is picked. The Filament Select behind it was never ->live(), so the
 *    field only appeared on the next round-trip (audit 73). Which types need a reason comes from the
 *    server, so the mail list and this form cannot disagree.
 *  - Presence is shown, never enforced (plan 2.10 #41): the Filament page took a five-minute cache
 *    lock on load and then refused every verdict unless the caller held it.
 *  - The activity log is read-only (plan 2.10 #12). It arrives as an ordinary table envelope at the
 *    top level, which is unambiguous because it is the only table on the page, so sorting, searching
 *    and paging work the way they do everywhere else.
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
  /** How many times the submission has been changed since it was made. */
  revisions: { type: Number, default: 0 },
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

/**
 * The one action this page renders itself, because it carries a live form.
 *
 * Everything that hands down a verdict was removed from this page: two screens offering the same
 * decision meant two sets of copy, two confirm dialogs and - since the queue owns the undo window
 * and the presence banner - two different levels of safety. The queue is the review surface.
 */
const CUSTOM = ['send-notification'];

const named = (name) => props.actions.find((action) => action.name === name) ?? null;

const headerActions = computed(() => props.actions.filter((action) => !CUSTOM.includes(action.name)));

/** Server-declared, so the link cannot drift from the route the action list carries. */
const reviewUrl = computed(() => named('review')?.url ?? null);
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

/**
 * Who else is looking at this record.
 *
 * Advisory, and that is the change: the Filament page took a five-minute cache lock on
 * load and then refused every verdict unless the caller held it, so a reviewer who opened
 * a record by link could do nothing with it. Presence is shown, never enforced.
 */
const others = computed(() => props.fursuit.presence?.others ?? []);

const presenceLabel = computed(() => {
  const names = others.value.map((viewer) => viewer.name);

  if (names.length === 0) {
    return null;
  }

  if (names.length === 1) {
    return `${names[0]} is also viewing this fursuit`;
  }

  return `${names.slice(0, -1).join(', ')} and ${names.at(-1)} are also viewing this fursuit`;
});

/* Send notification. The reason field appears the moment the type is picked. */
const notifyOpen = ref(false);
const notifyForm = useForm({ notification_type: '', rejection_reason: '' });

/*
 * Which types need something to say to the attendee comes from the server (`needsReason`), so the
 * list of mails and the shape of this form cannot disagree. It used to be hardcoded to "rejected",
 * which silently dropped the reason when the publication-block mail was added.
 */
const notifyNeedsReason = computed(() => props.notificationTypes
    .find((type) => type.value === notifyForm.notification_type)?.needsReason === true);

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
        Presence, not a lock. It says who else is here and leaves the decision to the
        reviewer; the queue already skips records somebody is on, so seeing this means
        somebody followed a link straight to the record - which is allowed.
      -->
      <div
        v-if="presenceLabel"
        class="flex items-center gap-2 rounded border border-state-warn/40 bg-state-warn/10 px-3 py-2 text-[12px] text-state-warn"
      >
        <ManageIcon name="users" />
        <span>{{ presenceLabel }}.</span>
      </div>

      <!--
        This submission has been changed since it was made. The pictures live on the review
        page, which is where two versions can be compared side by side; here it is a pointer,
        because judging a resubmission from a count alone is the thing that goes wrong.
      -->
      <div
        v-if="revisions > 0"
        class="flex items-center gap-2 rounded border border-hairline bg-mg-surface-1 px-3 py-2 text-[12px] text-fg-2"
      >
        <ManageIcon name="rotate-ccw" :size="14" />
        <span>
          Changed {{ revisions }} time{{ revisions === 1 ? '' : 's' }} since it was submitted.
        </span>
        <a :href="reviewUrl" class="ml-auto text-state-live underline">Compare in the queue</a>
      </div>

      <!--
        The infolist: a twelve-column grid, image on three and everything else on nine,
        transcribed from FursuitResource::infolist().
      -->
      <section class="grid grid-cols-1 gap-3 md:grid-cols-12">
        <div class="md:col-span-3">
          <div class="flex items-center justify-center rounded border border-hairline bg-mg-surface-1 p-2">
            <!--
              A photo whose gallery render is still queued shows this rather than the
              archival master: the master is a print file, and a preview box is not worth
              a megabyte plus the wait that comes with it.
            -->
            <div
              v-if="fursuit.imageProcessing"
              class="flex h-40 flex-col items-center justify-center gap-1 text-center text-[12px] text-fg-3"
            >
              <ManageIcon name="loader" :size="18" class="animate-spin" />
              <span>Photo still processing</span>
              <span class="text-[11px]">Reload in a moment.</span>
            </div>
            <img
              v-else-if="fursuit.image"
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
            </div>
          </div>

          <!--
            The other half of a verdict. `status` says whether the card may be printed and
            handed out; this says whether the fursuit may be shown. They are independent on
            purpose: a photo that is not a photo of a suit used to be rejected outright,
            which cost the attendee a badge over a gallery rule.
          -->
          <div
            v-if="fursuit.publication.blocked"
            class="rounded border border-state-warn/40 bg-state-warn/10 px-3 py-2"
          >
            <div class="flex items-center gap-2">
              <ManageIcon name="eye-off" :size="16" class="text-state-warn" />
              <span class="text-[12px] font-medium text-state-warn">Blocked from the gallery and the game</span>
            </div>
            <p v-if="fursuit.publication.reason" class="pt-1 text-[12px] text-fg-2">
              {{ fursuit.publication.reason }}
            </p>
            <p class="text-[11px] text-fg-3">
              The badge is approved: it is printed and handed out as normal.
            </p>
          </div>
        </div>
      </section>

      <FormSection title="Activity" description="Read-only record of what happened to this fursuit.">
        <DataTable :table="activities" searchable />
      </FormSection>
    </div>

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

      <label v-if="notifyNeedsReason" class="flex flex-col gap-1">
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
