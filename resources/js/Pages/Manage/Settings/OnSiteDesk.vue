<script setup>
/**
 * Settings > On-Site Desk.
 *
 * Everything the badge desk publishes about itself: when it is open, and which booth an
 * attendee queues at. Both are per-event columns the Events form has never owned
 * (`events.desk_opening_hours`, `events.pickup_booths`) and both are read straight back by
 * the public pickup page, so a save here is live for attendees on their next page load.
 * This is the successor to Tools > Pickup Booths, which is gone.
 *
 * TWO ROW BUILDERS, NO FREE TEXT.
 *
 * Opening hours and booth ranges are both typed inputs, one row per day and one per booth.
 * Neither value is large, but both are read by attendees rather than by staff, and the
 * failure mode is the same: a typo in a range sends someone to a desk that will not serve
 * them. The server assembles the stored structure from validated numbers, so a bad row
 * comes back as an error under the input that caused it instead of being normalized away.
 *
 * The per-booth counts are not on the page. They answer "is the saved split balanced",
 * which is a question asked while tuning ranges and never again, so they live behind a
 * button in the Booth ranges header and open in a dialog. On the page they pushed the two
 * editors apart and made a configuration screen read as a dashboard.
 *
 * They describe the *saved* split, not the draft in the form: the draft is unsaved and may
 * not even be a valid split yet, so a count against it would be a number for a thing that
 * does not exist. The dialog says so.
 *
 * Two forms, two submits, one screen. They are separate because they fail separately: a
 * rejected booth row must not throw away typed opening hours, and Inertia's error bag is
 * per request.
 */
import { computed, ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import dayjs from 'dayjs';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import ManageDialog from '@/Components/Manage/ManageDialog.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';

const props = defineProps({
  event: { type: Object, default: null },
  canEdit: { type: Boolean, default: false },
  /** [{ date: 'YYYY-MM-DD', opens, closes, note }], empty when the event publishes none. */
  openingHours: { type: Array, default: () => [] },
  /** False while the event has no split of its own and follows the defaults. */
  isConfigured: { type: Boolean, default: false },
  /** [{ label, from, to }]; `label` null means "derive it", `to` null the open-ended booth. */
  booths: { type: Array, required: true },
  defaults: { type: Array, required: true },
  /** { booths: [{label, from, to, attendees, badges}], totals: {attendees, badges, unassigned} } */
  counts: { type: Object, required: true },
  maxHourRows: { type: Number, default: 20 },
});

/* ------------------------------------------------------------------ opening hours */

const hoursForm = useForm({
  // Copied, not referenced: the prop is replaced wholesale on every Inertia response, and
  // editing it in place would make the "unsaved changes" comparison meaningless.
  hours: props.openingHours.map((row) => ({ ...row, note: row.note ?? '', reminds_at: row.reminds_at ?? '' })),
});

/**
 * The convention itself, which is the only span the desk can be open in.
 *
 * The picker is bounded by it and so is the server (`updateHours`), because a date input
 * with a `max` is a hint an operator can type straight past: the browser marks the field
 * invalid without stopping the submit, and a day outside the convention would publish an
 * opening time for a hall nobody is in.
 */
const firstDay = computed(() => props.event?.startsAt ?? null);
const lastDay = computed(() => props.event?.endsAt ?? null);

/** Held inside the convention, so a default never lands on a day the picker refuses. */
const clampToEvent = (date) => {
  if (!date) {
    return '';
  }

  if (firstDay.value && date < firstDay.value) {
    return firstDay.value;
  }

  if (lastDay.value && date > lastDay.value) {
    return lastDay.value;
  }

  return date;
};

const addHourRow = () => {
  const previous = hoursForm.hours[hoursForm.hours.length - 1];

  // The next convention day, at the times the day before it ran. A desk schedule is
  // mostly the same day repeated, and the alternative default - today - is almost never
  // the day being configured. The last day repeats itself rather than running past the
  // end of the convention, and the duplicate is what the distinct rule then points at.
  hoursForm.hours.push({
    date: clampToEvent(
      previous?.date
        ? dayjs(previous.date).add(1, 'day').format('YYYY-MM-DD')
        : firstDay.value ?? '',
    ),
    opens: previous?.opens ?? '10:00',
    closes: previous?.closes ?? '18:00',
    note: '',
    // Deliberately blank rather than copied from the day before: a reminder mails every
    // attendee still holding a badge, so each day it goes out is a decision somebody makes.
    reminds_at: '',
  });
};

// The weekday is derived, never stored: a date and a weekday kept side by side are two
// values that can disagree, and only one of them is what the desk actually meant.
const weekday = (date) => (date && dayjs(date).isValid() ? dayjs(date).format('dddd') : '');

const removeHourRow = (index) => hoursForm.hours.splice(index, 1);

const hourError = (index, field) => hoursForm.errors[`hours.${index}.${field}`];

const submitHours = () => hoursForm.put(route('admin.settings.on-site-desk.hours'), { preserveScroll: true });

const hoursDirty = computed(
  () => JSON.stringify(hoursForm.hours)
    !== JSON.stringify(props.openingHours.map((row) => ({ ...row, note: row.note ?? '', reminds_at: row.reminds_at ?? '' }))),
);

const canAddHourRow = computed(() => hoursForm.hours.length < props.maxHourRows);

// The bounds are named in the copy as well as enforced on the control, because a date
// input that silently refuses a day out of range explains nothing about why.
const hoursDescription = computed(() => {
  const base = 'One row per convention day, by date. Shown on the public pickup page; publish nothing and the page stays quiet about times. "Remind at" mails everybody who still has an uncollected badge that day, once each; leave it blank on days that should send nothing.';

  if (!firstDay.value || !lastDay.value) {
    return base;
  }

  return `${base} Dates are limited to ${dayjs(firstDay.value).format('D MMM YYYY')} to ${dayjs(lastDay.value).format('D MMM YYYY')}, the event's own dates.`;
});

/* -------------------------------------------------------------------- booth ranges */

// Cloned rather than bound: `props.booths` is replaced wholesale by every Inertia
// response, and editing it in place would leave the dirty comparison with nothing to
// compare against. `label` and `to` become '' so the inputs are controlled; the server
// reads both empty strings back as null.
const boothRowsFrom = (rows) =>
  rows.map((booth) => ({ label: booth.label ?? '', from: booth.from, to: booth.to ?? '' }));

const boothsForm = useForm({ booths: boothRowsFrom(props.booths) });

const submitBooths = () => boothsForm.put(route('admin.settings.on-site-desk.booths'), { preserveScroll: true });

const boothError = (index, field) => boothsForm.errors[`booths.${index}.${field}`];

const boothsDirty = computed(
  () => JSON.stringify(boothsForm.booths) !== JSON.stringify(boothRowsFrom(props.booths)),
);

const addBoothRow = () => {
  const previous = boothsForm.booths[boothsForm.booths.length - 1];

  // A new booth starts where the last one ended, because that is the only start that
  // does not leave a gap, and a gap is a save the server will refuse.
  boothsForm.booths.push({
    label: '',
    from: previous && previous.to !== '' ? Number(previous.to) + 1 : 0,
    to: '',
  });
};

const removeBoothRow = (index) => boothsForm.booths.splice(index, 1);

const confirmingReset = ref(false);
const showingSplit = ref(false);

const resetToDefaults = () => {
  confirmingReset.value = false;
  router.post(route('admin.settings.on-site-desk.booths.reset'), {}, { preserveScroll: true });
};

const loadDefaultsIntoEditor = () => {
  boothsForm.booths = boothRowsFrom(props.defaults);
};

// The derived caption, the same rule PickupBooths::label() applies server side, shown as
// the placeholder so an operator can see what a blank label will read as.
const derivedLabel = (row) =>
  row.to === '' || row.to === null ? `${row.from ?? 0} and up` : `${row.from ?? 0} – ${row.to}`;

const boothRows = computed(() => props.counts.booths ?? []);
const totals = computed(() => props.counts.totals ?? { attendees: 0, badges: 0, unassigned: 0 });

/*
 * An even split would give every booth the same share. The deviation is what an operator
 * is actually looking for, so it is shown per row rather than left to be eyeballed from
 * six raw numbers.
 */
const idealPerBooth = computed(() =>
  boothRows.value.length ? totals.value.attendees / boothRows.value.length : 0,
);

const deviation = (attendees) => {
  if (!idealPerBooth.value) return null;

  return Math.round(((attendees - idealPerBooth.value) / idealPerBooth.value) * 100);
};

const barWidth = (attendees) => {
  const max = Math.max(...boothRows.value.map((booth) => booth.attendees), 1);

  return `${Math.round((attendees / max) * 100)}%`;
};

const scope = computed(() => (props.event
  ? `Editing ${props.event.name}, the event selected in the header. The public pickup page and the attendee badge pages read these values back directly.`
  : 'No event is selected in the header. The desk is configured per event, so pick one before editing it.'));

const control =
  'h-8 w-full rounded border border-hairline bg-mg-surface-2 px-2 text-[13px] text-fg-1 outline-none transition-colors focus:border-state-live/50 disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
  <Head title="On-Site Desk settings" />

  <SettingsLayout>
    <p class="text-[13px] leading-[18px] text-fg-3">{{ scope }}</p>

    <!-- ============================================================ opening hours -->
    <FormSection
      title="Opening hours"
      :description="hoursDescription"
    >
      <form class="space-y-2 py-2" @submit.prevent="submitHours">
        <div v-if="hoursForm.hours.length" class="space-y-1.5">
          <!-- The column headings, once, rather than a label per input on every row: the
               grid is four narrow controls and repeating "Date / Opens / Closes" five times
               is noise. -->
          <div
            class="grid grid-cols-[8.75rem_5.25rem_5.25rem_5.25rem_minmax(0,1fr)_2rem] xl:grid-cols-[9.5rem_4.5rem_5.5rem_5.5rem_5.5rem_minmax(0,1fr)_2rem] items-center gap-2 px-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-fg-3"
          >
            <span>Date</span>
            <span class="hidden xl:block"></span>
            <span>Opens</span>
            <span>Closes</span>
            <span>Remind at</span>
            <span>Note (optional)</span>
            <span class="sr-only">Remove</span>
          </div>

          <div
            v-for="(row, index) in hoursForm.hours"
            :key="index"
            class="grid grid-cols-[8.75rem_5.25rem_5.25rem_5.25rem_minmax(0,1fr)_2rem] xl:grid-cols-[9.5rem_4.5rem_5.5rem_5.5rem_5.5rem_minmax(0,1fr)_2rem] items-start gap-2"
          >
            <div class="min-w-0">
              <input
                v-model="row.date"
                type="date"
                :class="control"
                :disabled="!canEdit"
                :min="firstDay"
                :max="lastDay"
              />
              <p v-if="hourError(index, 'date')" class="mt-1 text-[11px] text-state-danger">
                {{ hourError(index, 'date') }}
              </p>
            </div>

            <!-- Read-only echo of the date, so an operator can sanity-check that the day
                 they picked is the day they meant without doing the arithmetic. -->
            <span class="hidden h-8 items-center text-[12px] text-fg-3 xl:flex">{{ weekday(row.date) }}</span>

            <div>
              <input v-model="row.opens" type="time" :class="control" :disabled="!canEdit" />
              <p v-if="hourError(index, 'opens')" class="mt-1 text-[11px] text-state-danger">
                {{ hourError(index, 'opens') }}
              </p>
            </div>

            <div>
              <input v-model="row.closes" type="time" :class="control" :disabled="!canEdit" />
              <p v-if="hourError(index, 'closes')" class="mt-1 text-[11px] text-state-danger">
                {{ hourError(index, 'closes') }}
              </p>
            </div>

            <!-- The day's pickup reminder. Blank on the first row and disabled there: the desk
                 opens that day, so nobody is late for a badge yet.

                 No min/max on the input, deliberately. A time input with bounds refuses to submit
                 at all and shows the browser's own bubble, in the browser's own language, so the
                 operator never sees our sentence about the desk being shut - and the bound the
                 browser enforces is not quite ours either, since the closing minute is out. The
                 server owns this rule and says it in English under the input. -->
            <div>
              <input
                v-model="row.reminds_at"
                type="time"
                :class="control"
                :disabled="!canEdit || index === 0"
                :title="index === 0
                  ? 'The first desk day does not send reminders.'
                  : 'Emails everybody with an uncollected badge, once, at this time.'"
              />
            </div>

            <div class="min-w-0">
              <input
                v-model="row.note"
                type="text"
                :class="control"
                :disabled="!canEdit"
                placeholder="Closed 13:00 – 14:00"
                maxlength="120"
              />
              <p v-if="hourError(index, 'note')" class="mt-1 text-[11px] text-state-danger">
                {{ hourError(index, 'note') }}
              </p>
            </div>

            <button
              v-if="canEdit"
              type="button"
              class="flex size-8 items-center justify-center rounded border border-hairline text-fg-3 transition-colors hover:bg-mg-surface-2 hover:text-state-danger"
              :aria-label="`Remove ${row.date || 'this row'}`"
              @click="removeHourRow(index)"
            >
              <ManageIcon name="trash-2" :size="14" />
            </button>
            <span v-else />

            <!-- Under the whole row rather than under its input: this is the only message on this
                 page that is a sentence, and a sentence in a 5rem column wraps to six lines. -->
            <p
              v-if="hourError(index, 'reminds_at')"
              class="col-span-full -mt-0.5 text-[11px] text-state-danger"
            >
              {{ hourError(index, 'reminds_at') }}
            </p>
          </div>
        </div>

        <p v-else class="text-[13px] text-fg-2">
          No opening hours published. The pickup page tells attendees where to go, but says
          nothing about when.
        </p>

        <p v-if="hoursForm.errors.hours" class="text-[12px] text-state-danger">{{ hoursForm.errors.hours }}</p>

        <div v-if="canEdit" class="flex items-center gap-2 pt-1">
          <button
            type="button"
            :disabled="!canAddHourRow"
            class="inline-flex h-8 items-center gap-1.5 rounded border border-hairline px-3 text-[13px] text-fg-2 transition-colors hover:bg-mg-surface-2 disabled:opacity-50"
            @click="addHourRow"
          >
            <ManageIcon name="plus" :size="14" />
            Add day
          </button>
          <span v-if="!canAddHourRow" class="text-[11px] text-fg-3">
            {{ maxHourRows }} rows is the maximum.
          </span>
        </div>
        <p v-else class="text-[12px] text-fg-3">Read-only: editing the desk is admin only.</p>

        <FormActions
          v-if="canEdit"
          :processing="hoursForm.processing"
          :dirty="hoursDirty"
          submit-label="Save opening hours"
        />
      </form>
    </FormSection>

    <!-- ============================================================== booth ranges -->
    <FormSection
      title="Booth ranges"
      description="One row per booth, in the order the signs are numbered. Ranges must run end to end with no gap and no overlap; leave the last booth's end empty so it takes everything above it."
    >
      <!-- The balance check, one click away rather than on the page: it is read while
           tuning ranges and never again, and on the page it pushed the two editors apart. -->
      <template #actions>
        <button
          type="button"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2.5 text-[12px] text-fg-2 transition-colors hover:bg-mg-surface-2 hover:text-fg-1"
          @click="showingSplit = true"
        >
          <ManageIcon name="bar-chart" :size="14" />
          Live split
        </button>
      </template>

      <form class="space-y-2 py-1" @submit.prevent="submitBooths">
        <div v-if="boothsForm.booths.length" class="space-y-1.5">
          <div
            class="grid grid-cols-[2.5rem_6.5rem_6.5rem_minmax(0,1fr)_2rem] items-center gap-2 px-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-fg-3"
          >
            <span>Booth</span>
            <span>From</span>
            <span>To</span>
            <span>Sign label (optional)</span>
            <span class="sr-only">Remove</span>
          </div>

          <div
            v-for="(row, index) in boothsForm.booths"
            :key="index"
            class="grid grid-cols-[2.5rem_6.5rem_6.5rem_minmax(0,1fr)_2rem] items-start gap-2"
          >
            <span class="flex h-8 items-center px-1 text-[13px] text-fg-3">{{ index + 1 }}</span>

            <div>
              <input
                v-model.number="row.from"
                type="number"
                min="0"
                step="1"
                :class="control"
                :disabled="!canEdit"
              />
              <p v-if="boothError(index, 'from')" class="mt-1 text-[11px] text-state-danger">
                {{ boothError(index, 'from') }}
              </p>
            </div>

            <div>
              <!-- Empty is meaningful here, so no `.number`: it would turn '' into 0 and
                   quietly close the open-ended booth at attendee zero. -->
              <input
                v-model="row.to"
                type="number"
                min="0"
                step="1"
                :class="control"
                :disabled="!canEdit"
                placeholder="and up"
              />
              <p v-if="boothError(index, 'to')" class="mt-1 text-[11px] text-state-danger">
                {{ boothError(index, 'to') }}
              </p>
            </div>

            <div class="min-w-0">
              <input
                v-model="row.label"
                type="text"
                :class="control"
                :disabled="!canEdit"
                :placeholder="derivedLabel(row)"
                maxlength="255"
              />
              <p v-if="boothError(index, 'label')" class="mt-1 text-[11px] text-state-danger">
                {{ boothError(index, 'label') }}
              </p>
            </div>

            <button
              v-if="canEdit"
              type="button"
              class="flex size-8 items-center justify-center rounded border border-hairline text-fg-3 transition-colors hover:bg-mg-surface-2 hover:text-state-danger"
              :aria-label="`Remove booth ${index + 1}`"
              @click="removeBoothRow(index)"
            >
              <ManageIcon name="trash-2" :size="14" />
            </button>
            <span v-else />
          </div>
        </div>

        <p v-else class="text-[13px] text-fg-2">
          No booths. Add at least one, or reset the event to the built-in split.
        </p>

        <p v-if="boothsForm.errors.booths" class="text-[12px] text-state-danger">{{ boothsForm.errors.booths }}</p>

        <div v-if="canEdit" class="pt-1">
          <button
            type="button"
            class="inline-flex h-8 items-center gap-1.5 rounded border border-hairline px-3 text-[13px] text-fg-2 transition-colors hover:bg-mg-surface-2"
            @click="addBoothRow"
          >
            <ManageIcon name="plus" :size="14" />
            Add booth
          </button>
        </div>
        <p v-else class="text-[12px] text-fg-3">Read-only: editing the split is admin only.</p>

        <FormActions
          v-if="canEdit"
          :processing="boothsForm.processing"
          :dirty="boothsDirty"
          submit-label="Save booths"
        >
          <template #secondary>
            <button
              type="button"
              class="h-8 rounded border border-hairline px-3 text-[13px] text-fg-2 transition-colors hover:bg-mg-surface-2"
              @click="loadDefaultsIntoEditor"
            >
              Load defaults into editor
            </button>
            <button
              v-if="isConfigured"
              type="button"
              class="h-8 rounded border border-hairline px-3 text-[13px] text-fg-2 transition-colors hover:bg-mg-surface-2"
              @click="confirmingReset = true"
            >
              Reset event to defaults
            </button>
          </template>
        </FormActions>
      </form>
    </FormSection>

    <!--
      The saved split, balanced or not. A dialog rather than a card, because it answers a
      question asked while tuning the ranges and never afterwards.
    -->
    <ManageDialog v-model:visible="showingSplit" header="Live split" width="34rem">
      <p class="text-[12px] text-fg-3">
        The split as <span class="text-fg-2">saved</span>, not the rows in the form. Deviation
        is against an even share across all booths.
      </p>

      <div class="space-y-1">
        <div
          v-for="(booth, index) in boothRows"
          :key="index"
          class="rounded border border-hairline bg-mg-surface-2 px-3 py-2"
        >
          <div class="flex items-baseline gap-3">
            <span class="text-[11px] font-medium uppercase tracking-wide text-fg-3">
              Booth {{ index + 1 }}
            </span>
            <span class="text-[13px] font-semibold text-fg-1">{{ booth.label }}</span>
            <span class="ml-auto text-[13px] text-fg-2">
              {{ booth.attendees }} attendees · {{ booth.badges }} badges
            </span>
            <span
              v-if="deviation(booth.attendees) !== null"
              class="w-14 text-right text-[12px]"
              :class="Math.abs(deviation(booth.attendees)) > 20 ? 'text-state-danger' : 'text-fg-3'"
            >
              {{ deviation(booth.attendees) > 0 ? '+' : '' }}{{ deviation(booth.attendees) }}%
            </span>
          </div>
          <div class="mt-1.5 h-1.5 w-full rounded bg-mg-surface-1">
            <div class="h-1.5 rounded bg-state-info" :style="{ width: barWidth(booth.attendees) }"></div>
          </div>
        </div>

        <p class="pt-1 text-[12px] text-fg-3">
          {{ totals.attendees }} attendees, {{ totals.badges }} badges covered.
          <span v-if="!isConfigured">This event has no split of its own and follows the built-in default.</span>
          <span v-if="totals.unassigned > 0" class="text-state-danger">
            {{ totals.unassigned }} badge(s) fall outside every booth and have no queue to join.
          </span>
        </p>
      </div>

      <template #footer>
        <button
          type="button"
          class="h-8 rounded border border-hairline px-3 text-[13px] text-fg-2 transition-colors hover:bg-mg-surface-2"
          @click="showingSplit = false"
        >
          Close
        </button>
      </template>
    </ManageDialog>

    <ManageDialog v-model:visible="confirmingReset" header="Reset booth ranges?">
      <p class="text-[13px] leading-[18px] text-fg-2">
        This drops
        <span class="text-fg-1">{{ event?.name ?? 'this event' }}</span
        >'s own booth split and follows the built-in default again. Attendees see the change
        on their next page load.
      </p>

      <template #footer>
        <button
          type="button"
          class="h-8 rounded border border-hairline px-3 text-[13px] text-fg-2 transition-colors hover:bg-mg-surface-2"
          @click="confirmingReset = false"
        >
          Cancel
        </button>
        <button
          type="button"
          class="h-8 rounded bg-state-danger px-3 text-[13px] font-medium text-mg-surface-0"
          @click="resetToDefaults"
        >
          Reset to defaults
        </button>
      </template>
    </ManageDialog>
  </SettingsLayout>
</template>
