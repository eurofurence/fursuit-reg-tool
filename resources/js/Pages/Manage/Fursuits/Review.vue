<script setup>
/**
 * The review queue: one fursuit, three verdicts, keyboard first.
 *
 * The record page (Fursuits/Show) shows everything about a fursuit. This page shows what a
 * verdict needs and nothing else, because a reviewer spends an afternoon here and works
 * hundreds of records: the photo big enough to judge on the left, the three verdicts down
 * the right, and each verdict's reasons as chips directly under it.
 *
 * The layout is what makes it fast, so it is worth saying why it is this shape.
 *
 *  - **Verdicts on the right, photo on the left.** The photo is the work; it gets the width.
 *    A row of buttons under a tall image is below the fold on a laptop.
 *  - **Reasons are chips under their own verdict, not a dialog.** A modal with a select was
 *    three gestures (open, pick from a list, confirm) and hid the photo behind itself while
 *    the reviewer decided what to say about it. A chip is one click, it belongs visibly to
 *    the button above it, and the photo stays on screen.
 *  - **Nothing is submitted by picking a reason.** Pick, then confirm - because a mis-click
 *    on a reason chip must not mail an attendee. The undo window is the second net.
 *
 * Four things it does that the Filament page did not.
 *
 *  - Three outcomes instead of yes/no, so a gallery rule no longer costs a badge.
 *  - Keyboard: A/R/G choose a verdict, 1-9 pick that verdict's reason, Enter confirms, the
 *    right arrow skips. Undo is a button and only a button - see onKey().
 *  - Undo, because every verdict waits out a window before the attendee is told.
 *  - Presence, which says who else is on the record instead of locking it.
 *
 * The poll is a partial reload of the three props that go stale on their own - presence, the
 * undo bar and the queue count - and it doubles as the presence heartbeat, because the server
 * refreshes presence when it renders this page.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

const props = defineProps({
  fursuit: { type: Object, required: true },
  /** The three outcomes, declared server-side with their reasons and shortcuts. */
  outcomes: { type: Array, default: () => [] },
  presence: { type: Object, required: true },
  /** The last verdict this reviewer can still take back, or null. */
  undo: { type: Object, default: null },
  queue: { type: Object, required: true },
});

/*
 * The verdict the reviewer is composing. A verdict that needs a reason stays selected until
 * a reason is picked and confirmed; a plain approval is submitted on the spot, because the
 * common case has to cost one key.
 */
const chosen = ref(null);
const form = useForm({ reason: '', custom_reason: '' });

const chosenOutcome = computed(() => props.outcomes.find((outcome) => outcome.value === chosen.value) ?? null);

const canConfirm = computed(() => {
  const outcome = chosenOutcome.value;

  return outcome !== null && (!outcome.requiresReason || form.custom_reason.trim() !== '');
});

/** Picking a chip fills the text that is actually sent; the reviewer may then edit it. */
const pickReason = (outcome, reason) => {
  chosen.value = outcome.value;
  form.reason = reason.value;
  form.custom_reason = reason.label;
};

const choose = (outcome) => {
  if (!outcome?.available || form.processing) {
    return;
  }

  if (!outcome.requiresReason) {
    submit(outcome);

    return;
  }

  // Re-selecting the verdict already in hand clears it, so the same key is also the way out.
  if (chosen.value === outcome.value) {
    reset();

    return;
  }

  chosen.value = outcome.value;
  form.reason = '';
  form.custom_reason = '';
};

const submit = (outcome) => {
  form.post(outcome.url, {
    onSuccess: () => reset(),
  });
};

const confirm = () => {
  if (canConfirm.value && !form.processing) {
    submit(chosenOutcome.value);
  }
};

const reset = () => {
  chosen.value = null;
  form.reset();
};

// A verdict that stops being available - somebody else decided in the meantime and the poll
// brought the new outcomes in - must not stay selected with a Confirm button under it.
watch(
  () => props.outcomes,
  (outcomes) => {
    if (chosen.value && !outcomes.find((outcome) => outcome.value === chosen.value && outcome.available)) {
      reset();
    }
  },
);

const undoLast = () => {
  if (props.undo) {
    router.post(props.undo.url, {});
  }
};

const skip = () => {
  router.get(props.queue.skipUrl);
};

/*
 * Keys. Bound on the window rather than on a focused element, because a reviewer's hands
 * never leave the keyboard - but anything typed into the reason box is left alone, and
 * Cmd/Ctrl+Enter confirms from inside it, where a bare Enter has to stay a newline.
 */
const typing = (event) => {
  const tag = event.target?.tagName;

  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || event.target?.isContentEditable;
};

const onKey = (event) => {
  if (event.key === 'Escape') {
    reset();

    return;
  }

  if (event.key === 'Enter' && (event.metaKey || event.ctrlKey || !typing(event))) {
    event.preventDefault();
    confirm();

    return;
  }

  if (event.metaKey || event.ctrlKey || event.altKey || typing(event)) {
    return;
  }

  /*
   * Undo is deliberately not on a key. Every other shortcut here moves work forward, and one
   * that reaches back and rewrites the previous record is the one gesture that should cost a
   * deliberate look at what it says it will undo - the bar names the fursuit and the verdict.
   * A stray arrow key next to the one that skips is not that.
   */
  if (event.key === 'ArrowRight') {
    event.preventDefault();
    skip();

    return;
  }

  // 1-9 pick a reason. Which list they index depends on the verdict in hand, so the digits
  // mean nothing until a verdict is chosen - and every chip prints its own digit.
  const digit = Number.parseInt(event.key, 10);

  if (!Number.isNaN(digit) && chosenOutcome.value?.reasons?.[digit - 1]) {
    event.preventDefault();
    pickReason(chosenOutcome.value, chosenOutcome.value.reasons[digit - 1]);

    return;
  }

  /*
   * Outcomes carry a list of keys, not one. When the attendee asked for neither the gallery
   * nor the game the block button is not offered at all - it would only be an approval - and
   * its `g` is folded into Approve, so the keystroke a reviewer reaches for on digital art
   * still works.
   */
  const outcome = props.outcomes.find((candidate) => candidate.shortcuts?.includes(event.key.toLowerCase()));

  if (outcome) {
    event.preventDefault();
    choose(outcome);
  }
};

let timer = null;
let clock = null;

/** Wall clock for the undo countdown, ticked by the interval below. */
const now = ref(Date.now());

const poll = () => {
  router.reload({ only: ['presence', 'undo', 'queue', 'outcomes'] });
};

onMounted(() => {
  window.addEventListener('keydown', onKey);
  timer = window.setInterval(poll, (props.presence.heartbeatSeconds ?? 15) * 1000);
  // A second, faster tick for the undo countdown only: counting down to the second over the
  // network would be one request per second per reviewer for a number the client can work out.
  clock = window.setInterval(() => {
    now.value = Date.now();
  }, 1000);
});

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey);

  if (timer !== null) {
    window.clearInterval(timer);
  }

  if (clock !== null) {
    window.clearInterval(clock);
  }
});

const others = computed(() => props.presence.others ?? []);

const othersLabel = computed(() => {
  const names = others.value.map((viewer) => viewer.name);

  if (names.length === 0) {
    return null;
  }

  if (names.length === 1) {
    return `${names[0]} is also on this fursuit`;
  }

  return `${names.slice(0, -1).join(', ')} and ${names.at(-1)} are also on this fursuit`;
});

const undoLeft = computed(() => {
  if (!props.undo?.expiresAt) {
    return null;
  }

  const seconds = Math.max(0, Math.round((new Date(props.undo.expiresAt).getTime() - now.value) / 1000));

  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});

const submitted = computed(() => {
  if (!props.fursuit.submittedAt) {
    return null;
  }

  return new Date(props.fursuit.submittedAt).toLocaleString();
});

const history = computed(() => props.fursuit.history ?? []);

/**
 * The headline a reviewer needs before looking at anything else on a resubmission: whether
 * the photo they were asked to replace was in fact replaced.
 */
const resubmission = computed(() => {
  if (history.value.length === 0) {
    return null;
  }

  const previous = history.value[0];

  return {
    at: previous.changedAt ? new Date(previous.changedAt).toLocaleString() : null,
    imageChanged: previous.imageChanged,
    versions: history.value.length,
  };
});

const shortDate = (value) => (value ? new Date(value).toLocaleString() : null);

const toneClasses = {
  ok: 'border-state-ok/40 text-state-ok hover:bg-state-ok/12',
  warn: 'border-state-warn/40 text-state-warn hover:bg-state-warn/12',
  danger: 'border-state-danger/40 text-state-danger hover:bg-state-danger/12',
  info: 'border-state-live/40 text-state-live hover:bg-state-live/12',
  idle: 'border-hairline text-fg-2 hover:bg-mg-surface-3',
};

const chosenRing = {
  ok: 'ring-1 ring-state-ok bg-state-ok/12',
  warn: 'ring-1 ring-state-warn bg-state-warn/12',
  danger: 'ring-1 ring-state-danger bg-state-danger/12',
  info: 'ring-1 ring-state-live bg-state-live/12',
  idle: 'ring-1 ring-hairline bg-mg-surface-3',
};

const confirmBg = {
  ok: 'bg-state-ok',
  warn: 'bg-state-warn',
  danger: 'bg-state-danger',
  info: 'bg-state-live',
  idle: 'bg-mg-surface-3',
};

const textarea =
  'w-full rounded border border-hairline bg-mg-surface-2 px-2 py-1.5 text-[12px] text-fg-1 outline-none focus:border-state-live/50';
</script>

<template>
  <Head :title="`Review ${fursuit.name ?? 'fursuit'}`" />

  <ManageLayout>
    <PageHeader title="Review" :subtitle="`${queue.remaining} waiting in this event`">
      <template #actions>
        <a
          :href="queue.recordUrl"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] font-medium text-fg-1 transition-colors hover:bg-mg-surface-3"
        >
          <ManageIcon name="eye" />
          Full record
        </a>
        <button
          type="button"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] font-medium text-fg-1 transition-colors hover:bg-mg-surface-3"
          @click="skip"
        >
          Skip
          <ManageIcon name="arrow-right" />
        </button>
      </template>
    </PageHeader>

    <div class="flex flex-col gap-3 p-4">
      <!--
        Presence. Advisory: it never refuses a verdict, it says who else is here and lets the
        reviewer decide. The queue already skips records somebody is on, so seeing this means
        somebody followed a link - which is allowed.
      -->
      <div
        v-if="othersLabel"
        class="flex items-center gap-2 rounded border border-state-warn/40 bg-state-warn/10 px-3 py-2 text-[12px] text-state-warn"
      >
        <ManageIcon name="users" />
        <span>{{ othersLabel }}. Decide anyway, or skip to avoid doing the same work twice.</span>
      </div>

      <section class="grid grid-cols-1 gap-3 lg:grid-cols-12">
        <!-- The photo and its history: the work, and what it looked like last time. -->
        <div class="flex flex-col gap-3 lg:col-span-8">
          <div class="flex items-center justify-center rounded border border-hairline bg-mg-surface-1 p-2">
            <img
              v-if="fursuit.image"
              :src="fursuit.image"
              :alt="fursuit.name ?? ''"
              class="max-h-[70vh] w-full rounded object-contain"
            />
            <span v-else class="flex h-60 items-center text-[12px] text-fg-3">No image</span>
          </div>

          <!--
            The resubmission banner. The one thing a reviewer has to know before judging a
            record that has been here before: whether the photo actually changed. An attendee
            who was told their image is not a photo of a costume and sent the same file back
            reads exactly like one who fixed it, without this.
          -->
          <div
            v-if="resubmission"
            class="rounded border px-3 py-2 text-[12px]"
            :class="resubmission.imageChanged
              ? 'border-state-live/40 bg-state-live/10 text-state-live'
              : 'border-state-warn/40 bg-state-warn/10 text-state-warn'"
          >
            <p class="font-medium">
              {{ resubmission.imageChanged ? 'Resubmitted with a new photo' : 'Resubmitted with the same photo' }}
            </p>
            <p class="mt-0.5">
              {{ resubmission.versions }} earlier version{{ resubmission.versions === 1 ? '' : 's' }}
              <template v-if="resubmission.at"> - last change {{ resubmission.at }}</template>
            </p>
          </div>

          <!-- Earlier versions, newest first, so the two photos can be compared side by side. -->
          <div v-if="history.length" class="rounded border border-hairline bg-mg-surface-1 px-3 py-2">
            <p class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Earlier versions</p>
            <div class="mt-2 flex gap-3 overflow-x-auto pb-1">
              <figure
                v-for="version in history"
                :key="version.id"
                class="w-40 shrink-0"
              >
                <img
                  v-if="version.image"
                  :src="version.image"
                  :alt="version.name ?? ''"
                  class="h-40 w-40 rounded border border-hairline object-cover"
                />
                <div
                  v-else
                  class="flex h-40 w-40 items-center justify-center rounded border border-dashed border-hairline text-center text-[11px] text-fg-3"
                >
                  Photo no longer stored
                </div>
                <figcaption class="pt-1 text-[11px] text-fg-3">
                  <span class="block text-fg-1">{{ version.name ?? '—' }}</span>
                  <span class="block">{{ version.species ?? '—' }}</span>
                  <span class="block">{{ shortDate(version.changedAt) ?? '' }}</span>
                  <span v-if="version.changedBy" class="block">by {{ version.changedBy }}</span>
                  <span class="block">
                    <template v-if="version.imageChanged">photo replaced</template>
                    <template v-else>same photo as the next version</template>
                  </span>
                </figcaption>
              </figure>
            </div>
          </div>
        </div>

        <!-- The decision column. -->
        <div class="flex flex-col gap-3 lg:col-span-4">
          <div class="rounded border border-hairline bg-mg-surface-1 px-3 py-2">
            <div class="flex items-center justify-between gap-3">
              <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Name</span>
              <StatusBadge :status="fursuit.status" />
            </div>
            <p class="text-[19px] font-bold text-fg-1">{{ fursuit.name ?? '—' }}</p>
            <p class="text-[13px] text-fg-2">{{ fursuit.species ?? 'No species' }}</p>

            <div class="mt-2 flex items-center gap-3 text-[11px] text-fg-3">
              <span class="inline-flex items-center gap-1">
                <ManageIcon
                  :name="fursuit.published ? 'circle-check' : 'circle-x'"
                  :size="14"
                  :class="fursuit.published ? 'text-state-ok' : 'text-fg-3'"
                />
                wants gallery
              </span>
              <span class="inline-flex items-center gap-1">
                <ManageIcon
                  :name="fursuit.catchEmAll ? 'circle-check' : 'circle-x'"
                  :size="14"
                  :class="fursuit.catchEmAll ? 'text-state-ok' : 'text-fg-3'"
                />
                wants the game
              </span>
            </div>
          </div>

          <div
            v-if="fursuit.publication.blocked"
            class="rounded border border-state-warn/40 bg-state-warn/10 px-3 py-2 text-[12px] text-state-warn"
          >
            <p class="font-medium">Blocked from the gallery and the game</p>
            <p v-if="fursuit.publication.reason" class="mt-0.5">{{ fursuit.publication.reason }}</p>
          </div>

          <!--
            The verdicts. Each one owns the reasons under it, so the reviewer never has to
            hold "which list belongs to which button" in their head, and the photo is never
            covered by a dialog while they choose.
          -->
          <div
            v-for="outcome in outcomes"
            :key="outcome.value"
            class="rounded border bg-mg-surface-1"
            :class="[
              toneClasses[outcome.tone] ?? toneClasses.idle,
              chosen === outcome.value ? (chosenRing[outcome.tone] ?? chosenRing.idle) : '',
              outcome.available ? '' : 'opacity-40',
            ]"
          >
            <button
              type="button"
              class="flex w-full flex-col gap-0.5 px-3 py-2 text-left disabled:cursor-not-allowed"
              :disabled="!outcome.available || form.processing"
              :title="outcome.unavailableReason ?? outcome.consequence"
              @click="choose(outcome)"
            >
              <span class="flex items-center gap-2 text-[14px] font-semibold">
                <ManageIcon :name="outcome.icon" :size="18" />
                {{ outcome.label }}
                <kbd class="ml-auto rounded border border-hairline px-1.5 py-0.5 text-[11px] uppercase text-fg-3">
                  {{ outcome.shortcut }}
                </kbd>
              </span>
              <span class="text-[11px] text-fg-3">
                {{ outcome.available ? outcome.consequence : outcome.unavailableReason }}
              </span>
            </button>

            <!--
              The reasons, as chips rather than a select: one click instead of open-pick-close,
              and the whole list is readable at a glance so a reviewer learns their positions.
              Shown for the chosen verdict only, so three lists are not competing for the eye.
            -->
            <div
              v-if="outcome.requiresReason && chosen === outcome.value"
              class="flex flex-col gap-2 border-t border-hairline px-3 py-2"
            >
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="(reason, index) in outcome.reasons"
                  :key="reason.value"
                  type="button"
                  class="max-w-full rounded border px-2 py-1 text-left text-[11px] transition-colors"
                  :class="form.reason === reason.value
                    ? 'border-state-live bg-state-live/15 text-fg-1'
                    : 'border-hairline text-fg-2 hover:bg-mg-surface-3'"
                  @click="pickReason(outcome, reason)"
                >
                  <span class="mr-1 text-fg-3">{{ index + 1 }}</span>
                  {{ reason.label }}
                </button>
              </div>

              <!--
                The text that is actually sent. Editable, because the chips are starting
                points: the eight rejection strings were always meant to be adjusted before
                they went out, and that is the behaviour the old modal had.
              -->
              <textarea
                v-model="form.custom_reason"
                rows="3"
                :class="textarea"
                placeholder="Pick a reason above, or write what the attendee should read."
              />
              <span v-if="form.errors.custom_reason" class="text-[11px] text-state-danger">
                {{ form.errors.custom_reason }}
              </span>

              <div class="flex items-center gap-2">
                <button
                  type="button"
                  class="h-7 rounded px-2.5 text-[12px] font-medium text-mg-surface-0 disabled:opacity-40"
                  :class="confirmBg[outcome.tone] ?? confirmBg.idle"
                  :disabled="!canConfirm || form.processing"
                  @click="confirm"
                >
                  Confirm
                  <kbd class="ml-1 text-[11px] opacity-80">&crarr;</kbd>
                </button>
                <button
                  type="button"
                  class="h-7 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-mg-surface-3"
                  @click="reset"
                >
                  Cancel
                </button>
              </div>
            </div>
          </div>

          <!--
            Undo. Present only while the verdict can still be erased: after the mail goes out
            the fix is a new verdict, so a button here would be a lie.
          -->
          <div
            v-if="undo"
            class="rounded border border-hairline bg-mg-surface-1 px-3 py-2"
          >
            <button
              type="button"
              class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] font-medium text-fg-1 transition-colors hover:bg-mg-surface-3"
              @click="undoLast"
            >
              <ManageIcon name="rotate-ccw" />
              Undo
            </button>
            <p class="pt-1 text-[12px] text-fg-2">
              {{ undo.outcome }} on <span class="text-fg-1">{{ undo.fursuit ?? 'a fursuit' }}</span>
              <span v-if="undoLeft" class="text-fg-3"> - nothing sent yet, {{ undoLeft }} left</span>
            </p>
          </div>

          <div class="rounded border border-hairline bg-mg-surface-1 px-3 py-2 text-[12px] text-fg-2">
            <div class="flex justify-between gap-3">
              <span class="text-fg-3">Attendee</span>
              <span class="text-fg-1">{{ fursuit.owner ?? 'unknown' }}</span>
            </div>
            <div class="flex justify-between gap-3">
              <span class="text-fg-3">Event</span>
              <span class="text-fg-1">{{ fursuit.event ?? '—' }}</span>
            </div>
            <div v-if="submitted" class="flex justify-between gap-3">
              <span class="text-fg-3">Submitted</span>
              <span class="text-fg-1">{{ submitted }}</span>
            </div>
            <div v-if="fursuit.badge" class="flex justify-between gap-3">
              <span class="text-fg-3">Badge</span>
              <span class="text-fg-1">{{ fursuit.badge.customId ?? `#${fursuit.badge.id}` }}</span>
            </div>
          </div>

          <!-- What was decided last time, so a resubmission is not judged blind. -->
          <div
            v-if="fursuit.lastDecision"
            class="rounded border border-hairline bg-mg-surface-1 px-3 py-2 text-[12px]"
          >
            <p class="font-medium text-fg-2">
              Previously: {{ fursuit.lastDecision.outcome }}
              <span v-if="fursuit.lastDecision.reviewer" class="text-fg-3">by {{ fursuit.lastDecision.reviewer }}</span>
            </p>
            <p v-if="fursuit.lastDecision.reason" class="mt-0.5 text-fg-3">{{ fursuit.lastDecision.reason }}</p>
          </div>

          <p class="text-[11px] text-fg-3">
            <template v-for="(outcome, index) in outcomes" :key="outcome.value">
              <span v-if="index > 0"> &middot; </span>
              <kbd class="rounded border border-hairline px-1 uppercase">{{ outcome.shortcut }}</kbd>
              {{ outcome.label }}
            </template>
            &middot; <kbd class="rounded border border-hairline px-1">1-9</kbd> reason
            &middot; <kbd class="rounded border border-hairline px-1">&crarr;</kbd> confirm
            &middot; <kbd class="rounded border border-hairline px-1">&rarr;</kbd> skip
          </p>
        </div>
      </section>
    </div>
  </ManageLayout>
</template>
