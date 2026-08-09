<script setup>
/**
 * Create and edit for a POS staff member, plus the RFID tags hanging off them.
 *
 * The tags were an old panel relation manager, which only ever rendered on this page, so
 * they stay on this page. Their envelope arrives as the same flat top-level props every
 * list uses (rows, columns, filters, sort, search, meta), because useTableQuery reloads
 * those five keys as an Inertia partial visit and partials are filtered by top-level key.
 *
 * The table is rendered here rather than through DataTable, and that is the one place
 * this module departs from the shared component. DataTable renders server-declared row
 * actions only, and a tag is created and edited in a dialog on this page: a link out to
 * an edit page would throw away whatever the operator had typed into the staff form
 * above, and driving the editor through an ActionButton field set gives a free-text
 * unique field no way to show its validation error. Everything else is shared -
 * FilterBar, Pagination, useTableQuery, ActionButton for the deletes, and the column,
 * filter and action declarations all still come from the server.
 *
 * This is also the reason FilterBar and Pagination are still components a page can mount
 * on its own after DataTable absorbed both. FilterBar draws no band chrome any more, only
 * its controls, so the toolbar band around it is written out below; the tag table declares
 * no toggleable columns, so there is no column toggle to sit at the far end of it.
 *
 * Three fixes are visible from here. The `Generate` button asks the server for an unused
 * setup code and writes it into form state; it persists on save and not before
 *. The PIN field says out loud that the value is stored in plain text,
 * because the list column reading `Set` / `Not Set` suggests otherwise.
 * And the PIN it is prefilled with is a sentinel rather than the PIN: the plaintext is
 * never shipped to the browser, so it is not in this page's props, its DOM or Inertia's
 * history state. Submitting the sentinel unchanged keeps the stored PIN;
 * emptying the field still clears it.
 */
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import FilterBar from '@/Components/Manage/FilterBar.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import ManageDialog from '@/Components/Manage/ManageDialog.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import Pagination from '@/Components/Manage/Pagination.vue';
import StatCard from '@/Components/Manage/StatCard.vue';
import { useTableQuery } from '@/Components/Manage/useTableQuery.js';

const props = defineProps({
  /** null on create. */
  staff: { type: Object, default: null },
  /** Flashed by the Generate button. Nothing is written until this form is saved. */
  generatedSetupCode: { type: String, default: null },
  headerActions: { type: Array, default: () => [] },

  /**
   * The member's own numbers, for one event or all time. Absent on create.
   * Reloaded on its own by the event picker; see `pickEvent` below.
   */
  statistics: { type: Object, default: null },
  statisticsEvents: { type: Array, default: () => [] },
  selectedEventId: { type: Number, default: null },

  /** The nested tag table. Absent on create, where there is no member to hang tags off. */
  name: { type: String, default: null },
  rows: { type: Array, default: null },
  columns: { type: Array, default: () => [] },
  hiddenColumns: { type: Array, default: () => [] },
  pageActions: { type: Array, default: () => [] },
  filters: { type: Array, default: () => [] },
  sort: { type: Object, default: null },
  search: { type: String, default: '' },
  meta: { type: Object, default: null },
  bulkActions: { type: Array, default: () => [] },
  canCreateRfidTag: { type: Boolean, default: false },
});

const editing = computed(() => Boolean(props.staff?.id));

/*
 * Which panel is showing. Local state, so switching to the numbers and back does not
 * discard a half-filled form or cost a round trip. Create has no numbers to show and
 * renders no strip at all.
 */
const panels = [
  { key: 'details', label: 'Details' },
  { key: 'statistics', label: 'Statistics' },
];

const tab = ref('details');

const form = useForm({
  name: props.staff?.name ?? '',
  pin_code: props.staff?.pin_code ?? '',
  setup_code: props.staff?.setup_code ?? '',
  is_active: props.staff?.is_active ?? true,
  is_manager: props.staff?.is_manager ?? false,
});

const submit = () => {
  if (editing.value) {
    form.put(route('admin.staff.update', props.staff.id));

    return;
  }

  form.post(route('admin.staff.store'));
};

/*
 * The suffix action the old panel put inside the Setup Code field: offered while creating, and
 * on an existing member only while they still have no PIN. Read off the persisted record
 * rather than the field, so typing a PIN does not make the button vanish mid-edit.
 */
// `pin_code` is the sentinel when a PIN is stored and empty when none is, so this reads
// the same question it always did without the plaintext ever being here.
const canGenerate = computed(() => !editing.value || !props.staff?.pin_code);

const generating = ref(false);

const generate = () => {
  generating.value = true;

  router.post(
    editing.value
      ? route('admin.staff.setup-code', props.staff.id)
      : route('admin.staff.setup-code.create'),
    {},
    {
      preserveScroll: true,
      // The typed name, PIN and toggle survive the round trip; only the proposed code
      // comes back.
      preserveState: true,
      onFinish: () => {
        generating.value = false;
      },
    },
  );
};

// Set on the way back from Generate, never on first load.
watch(
  () => props.generatedSetupCode,
  (code) => {
    if (code) {
      form.setup_code = code;
    }
  },
);

/*
 * The statistics panel.
 *
 * Everything below is presentation of numbers the server already decided. The one thing
 * decided here is the event, and switching it reloads `statistics` and `selectedEventId`
 * and nothing else: the form above keeps whatever has been typed into it, and the RFID
 * table below does not re-run its query.
 */
const eventChoice = computed(() =>
  props.selectedEventId === null ? 'all' : String(props.selectedEventId),
);

const loadingStats = ref(false);

const pickEvent = (event) => {
  loadingStats.value = true;

  router.get(
    route('admin.staff.edit', props.staff.id),
    { event: event.target.value },
    {
      only: ['statistics', 'selectedEventId'],
      preserveState: true,
      preserveScroll: true,
      replace: true,
      onFinish: () => {
        loadingStats.value = false;
      },
    },
  );
};

const EMPTY = '—';

/** Seconds as the coarsest unit that still says something: `4m 12s`, `6h 30m`. */
const duration = (seconds) => {
  if (seconds === null || seconds === undefined) return EMPTY;
  if (seconds < 60) return `${Math.round(seconds)}s`;

  const minutes = Math.floor(seconds / 60);

  if (minutes < 60) return `${minutes}m ${Math.round(seconds % 60)}s`;

  return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
};

// Cents in, euros out. The payload is cents the whole way here for the same reason the
// POS statistics page keeps them: mixing the two units is what made the old page
// multiply by 100 in one place and not another.
const euros = (cents) =>
  new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format((cents ?? 0) / 100);

const perHour = (rate) => (rate === null || rate === undefined ? 'Too short to rate' : `${rate} an hour`);

const clockHour = (hour) => `${String(hour).padStart(2, '0')}:00`;

const stats = computed(() => props.statistics);

const cards = computed(() => {
  const s = props.statistics;

  if (!s) return [];

  return [
    {
      key: 'badges',
      label: 'Badges handed out',
      value: s.handovers.badges,
      description: perHour(s.handovers.perHour),
      icon: 'package-check',
    },
    {
      key: 'hours',
      label: 'Hours worked',
      value: s.time.activeHours,
      description: `${s.time.shifts} ${s.time.shifts === 1 ? 'shift' : 'shifts'}, split on ${s.time.idleGapMinutes} min idle`,
      icon: 'clock',
    },
    {
      key: 'avg',
      label: 'Avg per transaction',
      value: duration(s.time.avgTransactionSeconds),
      description: `Median ${duration(s.time.medianTransactionSeconds)}`,
      icon: 'hourglass',
    },
    {
      key: 'longest',
      label: 'Longest transaction',
      value: duration(s.time.longestTransactionSeconds),
      description: `Of ${s.time.actions} actions`,
      icon: 'hourglass',
    },
    {
      key: 'transacting',
      label: 'Time in transactions',
      value: duration(s.time.transactionSeconds),
      description: perHour(s.time.actionsPerHour),
      icon: 'hand',
    },
    {
      key: 'busiest',
      label: 'Busiest hour',
      value: s.busiestHour ? clockHour(s.busiestHour.hour) : EMPTY,
      description: s.busiestHour ? `${s.busiestHour.actions} actions` : 'No activity recorded',
      icon: 'trending-up',
    },
    {
      key: 'checkouts',
      label: 'Checkouts',
      value: s.checkouts.count,
      description: `${euros(s.checkouts.revenueCents)} taken`,
      icon: 'receipt',
    },
    {
      key: 'runs',
      label: 'Print runs sent',
      value: s.printing.runs,
      description: `${s.printing.printedCards} of ${s.printing.cards} cards printed`,
      icon: 'printer',
    },
  ];
});

/*
 * The tag table.
 */
const { toggleSort } = useTableQuery();

const selected = ref([]);

watch(
  () => props.rows,
  (rows) => {
    const ids = (rows ?? []).map((row) => row.id);
    selected.value = selected.value.filter((id) => ids.includes(id));
  },
);

const allSelected = computed(
  () => (props.rows?.length ?? 0) > 0 && selected.value.length === props.rows.length,
);

const toggleAll = () => {
  selected.value = allSelected.value ? [] : props.rows.map((row) => row.id);
};

const cell = (row, key) => row.cells?.[key] ?? null;

const isEmpty = (value) => value === null || value === undefined || value === '';

const copy = async (value) => {
  try {
    await navigator.clipboard.writeText(String(value));
  } catch {
    // Clipboard is unavailable outside a secure context; nothing to recover from.
  }
};

const align = {
  left: 'text-left',
  right: 'text-right',
  center: 'text-center',
};

/*
 * The tag editor. `tag` is null while closed, the row while editing, and an empty draft
 * while creating.
 */
const tag = ref(null);

const tagForm = useForm({
  content: '',
  name: '',
  is_active: true,
});

const openTag = (row = null) => {
  tagForm.clearErrors();
  tagForm.content = row ? cell(row, 'content') ?? '' : '';
  tagForm.name = row ? cell(row, 'name') ?? '' : '';
  tagForm.is_active = row ? Boolean(cell(row, 'is_active')) : true;
  tag.value = row ?? {};
};

const closeTag = () => {
  tag.value = null;
};

const submitTag = () => {
  const options = { preserveScroll: true, onSuccess: closeTag };

  if (tag.value?.id) {
    tagForm.put(route('admin.staff.rfid-tags.update', [props.staff.id, tag.value.id]), options);

    return;
  }

  tagForm.post(route('admin.staff.rfid-tags.store', props.staff.id), options);
};
</script>

<template>
  <Head :title="editing ? 'Edit staff' : 'New staff'" />

  <ManageLayout>
    <PageHeader
      :title="editing ? 'Edit staff' : 'New staff'"
      :subtitle="editing ? staff.name : null"
      :actions="headerActions"
    />

    <!--
      Local tabs, not the shared TabBar: that one is a table's preset views and keeps its
      state in the query string through useTableQuery. There is no table here, and putting
      the panel in the URL would make switching to it a visit that discards a half-filled
      form.
    -->
    <div
      v-if="editing"
      role="tablist"
      aria-label="Staff record"
      class="flex h-8 shrink-0 items-center gap-4 border-b border-hairline px-4"
    >
      <button
        v-for="panel in panels"
        :key="panel.key"
        type="button"
        role="tab"
        :aria-selected="tab === panel.key"
        class="-mb-px inline-flex h-8 shrink-0 items-center whitespace-nowrap border-b-2 text-[13px] leading-none outline-none transition-colors focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-state-live/40"
        :class="
          tab === panel.key
            ? 'border-state-live text-fg-1'
            : 'border-transparent text-fg-2 hover:text-fg-1'
        "
        @click="tab = panel.key"
      >
        {{ panel.label }}
      </button>
    </div>

    <form v-show="tab === 'details'" class="flex flex-col" @submit.prevent="submit">
      <div class="space-y-3 p-4">
        <FormSection title="Staff">
          <FormField
            v-model="form.name"
            label="Name"
            :error="form.errors.name"
            required
          />

          <!--
            A text input, not a number one: `->numeric()` made the browser coerce the
            value, so a PIN with a leading zero lost it on the way in. The
            server validates `digits:6` on the string.
          -->
          <FormField
            v-model="form.pin_code"
            label="PIN Code (6 digits)"
            helper="Enter a secure 6-digit PIN code. Leave empty to require setup code first. Stored in plain text and compared in plain text at POS login; it is not hashed."
            :error="form.errors.pin_code"
            narrow
          />

          <FormField
            label="Setup Code"
            helper="6-character alphanumeric code for initial account setup. Auto-generated if left empty."
            :error="form.errors.setup_code"
          >
            <div class="flex items-center gap-2">
              <input
                v-model="form.setup_code"
                type="text"
                maxlength="6"
                class="h-8 w-40 rounded border border-hairline bg-mg-surface-2 px-2 font-mono text-[13px] uppercase text-fg-1 outline-none transition-colors focus:border-state-live/50"
              />

              <button
                v-if="canGenerate"
                type="button"
                class="inline-flex h-8 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-mg-surface-3 disabled:opacity-50"
                :disabled="generating"
                @click="generate"
              >
                <ManageIcon name="refresh-cw" :size="13" />
                Generate
              </button>
            </div>
          </FormField>

          <!--
            The archive switch. Clearing it stamps `archived_at` and locks the member out
            of the POS; it never deletes them, because their handovers, tills and print
            runs all hang off this row.
          -->
          <FormField
            v-model="form.is_active"
            label="Active"
            type="toggle"
            :helper="
              staff?.archived_at
                ? `Archived. Inactive staff cannot login to POS.`
                : 'Inactive staff cannot login to POS. Nothing is deleted; their statistics are kept.'
            "
            :error="form.errors.is_active"
          />

          <FormField
            v-model="form.is_manager"
            label="Manager"
            type="toggle"
            helper="Managers can override badge prices at the POS, and approve an override for another cashier with their PIN or RFID tag"
            :error="form.errors.is_manager"
          />
        </FormSection>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="editing ? 'Save changes' : 'Create'"
      />
    </form>

    <!-- Edit only: a member with no record yet has nothing to count. -->
    <section v-if="stats" v-show="tab === 'statistics'" class="px-4 pt-4 pb-4">
      <div class="rounded border border-hairline bg-mg-surface-1">
        <header class="flex h-10 flex-wrap items-center gap-2 border-b border-hairline px-3">
          <h2 class="text-[12px] font-semibold uppercase tracking-wide text-fg-1">Performance</h2>

          <select
            :value="eventChoice"
            class="ml-auto h-7 rounded border border-hairline bg-mg-surface-2 px-2 text-[12px] text-fg-1 outline-none transition-colors focus:border-state-live/50"
            aria-label="Event"
            @change="pickEvent"
          >
            <option v-for="option in statisticsEvents" :key="option.id" :value="String(option.id)">
              {{ option.name }}
            </option>
            <option value="all">All time</option>
          </select>
        </header>

        <div class="p-3" :class="loadingStats ? 'opacity-50 transition-opacity' : ''">
          <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
            <StatCard v-for="card in cards" :key="card.key" :stat="card" />
          </div>

          <p class="mt-2 text-[11px] leading-relaxed text-fg-3">
            Hours are reconstructed from the timestamps on handovers, checkouts and print runs:
            a gap over {{ stats.time.idleGapMinutes }} minutes ends a shift and is not counted as
            worked. A transaction is the interval between two consecutive actions in one shift.
          </p>

          <div v-if="stats.perDay.length" class="mt-3 overflow-x-auto rounded border border-hairline">
            <table class="w-full border-collapse text-[13px]">
              <thead>
                <tr class="border-b border-hairline bg-mg-surface-2 text-[11px] uppercase tracking-wide text-fg-2">
                  <th class="h-7 px-3 text-left font-medium">Day</th>
                  <th class="h-7 px-3 text-right font-medium">Hours</th>
                  <th class="h-7 px-3 text-right font-medium">Shifts</th>
                  <th class="h-7 px-3 text-right font-medium">Badges</th>
                  <th class="h-7 px-3 text-right font-medium">Per hour</th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="day in stats.perDay"
                  :key="day.date"
                  class="border-b border-hairline/60 last:border-0"
                >
                  <td class="h-8 px-3 whitespace-nowrap text-fg-1">{{ day.date }}</td>
                  <td class="h-8 px-3 text-right tabular-nums text-fg-1">{{ day.hours }}</td>
                  <td class="h-8 px-3 text-right tabular-nums text-fg-2">{{ day.shifts }}</td>
                  <td class="h-8 px-3 text-right tabular-nums text-fg-1">{{ day.badges }}</td>
                  <td class="h-8 px-3 text-right tabular-nums text-fg-2">{{ day.perHour ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <p v-else class="mt-3 text-[13px] text-fg-3">
            Nothing recorded for this member here yet.
          </p>
        </div>
      </div>
    </section>

    <!-- The relation manager, as a card under the form. Edit only: a tag needs a member. -->
    <section v-if="rows" v-show="tab === 'details'" class="px-4 pb-4">
      <div class="rounded border border-hairline bg-mg-surface-1">
        <header class="flex h-10 items-center gap-2 border-b border-hairline px-3">
          <h2 class="text-[12px] font-semibold uppercase tracking-wide text-fg-1">Rfid tags</h2>

          <button
            v-if="canCreateRfidTag"
            type="button"
            class="ml-auto inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-mg-surface-3"
            @click="openTag()"
          >
            <ManageIcon name="plus" :size="13" />
            Create rfid tag
          </button>
        </header>

        <div
          v-if="selected.length && bulkActions.length"
          class="flex h-10 items-center gap-2 border-b border-hairline bg-mg-surface-2 px-3"
        >
          <span class="text-[12px] text-fg-2">{{ selected.length }} selected</span>
          <ActionButton
            v-for="action in bulkActions"
            :key="action.name"
            :action="action"
            :data="{ ids: selected }"
          />
          <button
            type="button"
            class="ml-auto text-[12px] text-fg-3 transition-colors hover:text-fg-1"
            @click="selected = []"
          >
            Clear
          </button>
        </div>

        <!-- The band DataTable's toolbar draws for every other list, written out here. -->
        <div
          class="flex min-h-11 flex-wrap items-center gap-x-2 gap-y-1.5 border-b border-hairline bg-mg-surface-1 px-3 py-2"
        >
          <FilterBar :filters="filters" :search="search" searchable />
        </div>

        <div class="overflow-x-auto">
          <table class="w-full border-collapse text-[13px]">
            <thead>
              <tr class="border-b border-hairline bg-mg-surface-2">
                <th v-if="bulkActions.length" class="w-8 px-3">
                  <input
                    type="checkbox"
                    :checked="allSelected"
                    class="accent-state-live"
                    aria-label="Select all tags"
                    @change="toggleAll"
                  />
                </th>
                <th
                  v-for="column in columns"
                  :key="column.key"
                  class="h-7 px-3 text-[11px] font-medium uppercase tracking-wide text-fg-2"
                  :class="align[column.align] ?? align.left"
                >
                  <button
                    v-if="column.sortable"
                    type="button"
                    class="inline-flex items-center gap-1 transition-colors hover:text-fg-1"
                    @click="toggleSort(column, sort)"
                  >
                    {{ column.label }}
                    <ManageIcon
                      v-if="sort?.key === column.key"
                      :name="sort.dir === 'asc' ? 'chevron-up' : 'chevron-down'"
                      :size="12"
                    />
                  </button>
                  <span v-else>{{ column.label }}</span>
                </th>
                <th class="px-3" />
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="row in rows"
                :key="row.id"
                class="border-b border-hairline/60 transition-colors hover:bg-mg-surface-2"
              >
                <td v-if="bulkActions.length" class="px-3">
                  <input
                    v-model="selected"
                    type="checkbox"
                    :value="row.id"
                    class="accent-state-live"
                    :aria-label="`Select tag ${row.id}`"
                  />
                </td>

                <td
                  v-for="column in columns"
                  :key="column.key"
                  class="h-8 px-3 whitespace-nowrap text-fg-1"
                  :class="align[column.align] ?? align.left"
                >
                  <template v-if="column.type === 'copyable'">
                    <button
                      v-if="!isEmpty(cell(row, column.key))"
                      type="button"
                      class="group inline-flex items-center gap-1 font-mono text-[12px] text-fg-1"
                      :title="`Copy ${cell(row, column.key)}`"
                      @click.stop="copy(cell(row, column.key))"
                    >
                      {{ cell(row, column.key) }}
                      <ManageIcon name="copy" :size="12" class="opacity-0 transition-opacity group-hover:opacity-60" />
                    </button>
                    <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
                  </template>

                  <template v-else-if="column.type === 'bool'">
                    <ManageIcon
                      :name="cell(row, column.key) ? 'circle-check' : 'circle-x'"
                      :size="15"
                      :class="cell(row, column.key) ? 'inline text-state-ok' : 'inline text-fg-3'"
                    />
                  </template>

                  <template v-else-if="column.type === 'datetime'">
                    <span v-if="!isEmpty(cell(row, column.key))" :title="cell(row, column.key)?.title">
                      {{ cell(row, column.key)?.display ?? cell(row, column.key) }}
                    </span>
                    <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
                  </template>

                  <template v-else>
                    <span v-if="!isEmpty(cell(row, column.key))">{{ cell(row, column.key) }}</span>
                    <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
                  </template>
                </td>

                <td class="px-3">
                  <div class="flex justify-end gap-1">
                    <button
                      type="button"
                      class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] font-medium text-fg-2 transition-colors hover:bg-mg-surface-3"
                      @click="openTag(row)"
                    >
                      <ManageIcon name="pencil" :size="13" />
                      Edit
                    </button>

                    <ActionButton
                      v-for="action in row.actions"
                      :key="action.name"
                      :action="action"
                    />
                  </div>
                </td>
              </tr>

              <tr v-if="!rows.length">
                <td :colspan="columns.length + 2" class="h-16 px-3 text-center text-[12px] text-fg-3">
                  No RFID tags
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <Pagination v-if="meta" :meta="meta" />
      </div>
    </section>

    <!--
      The tag editor. A dialog rather than a page, so opening it cannot discard unsaved
      changes in the staff form above, and errors land under the field that caused them.
    -->
    <!--
      `tag` is the object being edited (`{}` while creating), so it doubles as the open
      flag. Closing is the only way it goes false, hence update:visible → closeTag.
      Dismissable mask is off: this dialog holds a form, and a stray click on the
      backdrop must not discard it.
    -->
    <ManageDialog
      :visible="Boolean(tag)"
      :header="tag?.id ? 'Edit rfid tag' : 'Create rfid tag'"
      width="34rem"
      :dismissable-mask="false"
      @update:visible="closeTag"
    >
      <!--
        The form stays in the content slot while its submit button lives in the footer
        slot, so they are wired by `form=`/`id=` rather than nesting. That keeps
        Enter-to-submit and native required validation, which a plain @click button
        on the footer would drop.
      -->
      <form id="rfid-tag-form" class="divide-y divide-hairline/40" @submit.prevent="submitTag">
        <FormField
          v-model="tagForm.content"
          label="RFID Code"
          helper="The unique identifier from the RFID tag"
          :error="tagForm.errors.content"
          required
          mono
        />

        <FormField
          v-model="tagForm.name"
          label="Tag Name (Optional)"
          helper="A friendly name for this RFID tag"
          :error="tagForm.errors.name"
        />

        <FormField
          v-model="tagForm.is_active"
          label="Active"
          type="toggle"
          helper="Inactive tags cannot be used for authentication"
          :error="tagForm.errors.is_active"
        />
      </form>

      <template #footer>
        <button
          type="button"
          class="h-8 rounded border border-hairline px-3 text-[13px] text-fg-2 transition-colors hover:bg-mg-surface-3"
          @click="closeTag"
        >
          Cancel
        </button>
        <button
          type="submit"
          form="rfid-tag-form"
          class="h-8 rounded bg-state-live px-3 text-[13px] font-medium text-mg-surface-0 transition-opacity disabled:opacity-50"
          :disabled="tagForm.processing"
        >
          {{ tag?.id ? 'Save changes' : 'Create' }}
        </button>
      </template>
    </ManageDialog>
  </ManageLayout>
</template>
