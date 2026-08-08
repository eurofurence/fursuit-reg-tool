<script setup>
/**
 * PDF Generator, the successor to App\Filament\Pages\PdfGenerator (audit 5.1).
 *
 * One form, two documents: a badge list grouped by range, one range per page, and a box
 * label. The form is entirely client state, because nothing here is saved - the two
 * buttons are links whose query string *is* the form, and the server reads it, renders
 * and streams. That is also why they are plain anchors rather than ActionButton: an
 * ActionButton GET is an Inertia `<Link>`, and an XHR cannot save a file. A same-tab
 * anchor can, and it keeps the failure path working too, since a refused generation
 * redirects back to this page with its toast instead of stranding a blank tab.
 *
 * The event is the panel's own selection (the header selector), not a field. The Filament
 * page read a session key nothing ever wrote and so always used the newest event
 * regardless of what the header said (plan 2.9, audit 63); the banner below states which
 * event the badge list will cover, so the answer is on screen rather than implied.
 *
 * Copy that is not verbatim from the Filament page is corrected on purpose, all of it
 * under plan 2.10 #32: the box-label option and callout said three labels per A4 page and
 * the code has only ever rendered one, and the badge-list callout said "all free badges"
 * and "3 columns" against a 12-column default over every badge the filter admits.
 */
import { computed, reactive } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import { resolve, toneButton } from '@/Components/Manage/tones.js';

const props = defineProps({
  /** The header's selected event, or null when the selection is "all events". */
  event: { type: Object, default: null },
  /** Server-declared select options, so the labels have one source. */
  pdfTypes: { type: Array, required: true },
  paymentStatuses: { type: Array, required: true },
  /** The Filament page's mount() state, verbatim. */
  defaults: { type: Object, required: true },
});

const form = reactive({ ...props.defaults });

const isBadgeList = computed(() => form.pdf_type === 'badge_list');

/** Validation errors survive the redirect a refused download performs. */
const errors = computed(() => usePage().props.errors ?? {});

const badgeListUrl = computed(() =>
  route('manage.tools.pdf.badge-list', {
    payment_status: form.payment_status,
    badge_ranges: form.badge_ranges,
    rows_per_column: form.rows_per_column,
    columns: form.columns,
    font_size: form.font_size,
  }),
);

const boxLabelsUrl = computed(() =>
  route('manage.tools.pdf.box-labels', {
    title: form.title,
    subtitle: form.subtitle,
  }),
);

const button =
  'inline-flex h-7 items-center gap-1.5 rounded border px-2 text-[12px] font-medium transition-colors';
</script>

<template>
  <Head title="PDF Generator" />

  <ManageLayout>
    <PageHeader title="PDF Generator" subtitle="Generate PDFs for badge management and box labeling">
      <template #actions>
        <!-- Filament's two header actions, each visible for its own pdf_type. `primary`
             is the panel's info tone, `success` its ok tone. The box-labels button
             carries `tag`, lucide's equivalent of the heroicon the original used;
             ManageIcon's map gained it in the phase-9 integration pass. -->
        <a
          v-if="isBadgeList"
          :href="badgeListUrl"
          :class="[button, resolve(toneButton, 'info')]"
        >
          <ManageIcon name="file-text" />
          <span>Generate Badge List PDF</span>
        </a>

        <a v-else :href="boxLabelsUrl" :class="[button, resolve(toneButton, 'ok')]">
          <ManageIcon name="tag" />
          <span>Generate Box Labels PDF</span>
        </a>
      </template>
    </PageHeader>

    <div class="flex-1 space-y-3 p-4">
      <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <div class="rounded border border-state-info/30 bg-state-info/10 px-3 py-2">
          <h3 class="text-[12px] font-semibold text-fg-1">Badge List PDF</h3>
          <p class="mt-1 text-[12px] text-fg-2">
            Generates a list of the selected event's badges, grouped by ranges (0-999,
            1000-1999, etc.), one range per page and 12 columns per page by default. Each
            cell is one badge number.
          </p>
        </div>

        <div class="rounded border border-state-ok/30 bg-state-ok/10 px-3 py-2">
          <h3 class="text-[12px] font-semibold text-fg-1">Box Labels PDF</h3>
          <p class="mt-1 text-[12px] text-fg-2">
            Generates one label per page for badge boxes, on a 210x94mm page (a third of
            an A4 sheet), with a configurable title and subtitle.
          </p>
        </div>
      </div>

      <FormSection title="PDF Generation Options" description="Generate PDFs for badge management">
        <FormField
          v-model="form.pdf_type"
          label="PDF Type"
          type="select"
          :options="pdfTypes"
          required
        />

        <template v-if="isBadgeList">
          <!-- Which event the list covers is the header's answer, not a field. Stated
               rather than implied, because this page used to ignore it (audit 63). -->
          <FormField
            label="Event"
            :model-value="event ? event.name : null"
            :helper="event ? null : 'Select an event in the header. A badge list is always one event.'"
            readonly
          />

          <FormField
            v-model="form.payment_status"
            label="Payment Status Filter"
            type="select"
            :options="paymentStatuses"
            :error="errors.payment_status"
            required
          />

          <FormField
            v-model="form.badge_ranges"
            label="Badge Ranges"
            type="textarea"
            placeholder="e.g., 1-1699,1700-2400,2401-3000"
            helper="Enter comma-separated ranges (e.g., 1-1699,1700-2400). Each range will be on a separate page."
            :error="errors.badge_ranges"
            required
          />

          <FormField
            v-model="form.rows_per_column"
            label="Rows per Column"
            type="number"
            placeholder="50"
            :min="1"
            :max="500"
            :error="errors.rows_per_column"
            narrow
          />

          <FormField
            v-model="form.columns"
            label="Number of Columns"
            type="number"
            placeholder="12"
            :min="1"
            :max="50"
            :error="errors.columns"
            narrow
          />

          <FormField
            v-model="form.font_size"
            label="Font Size (px)"
            type="number"
            placeholder="6"
            :min="1"
            :max="72"
            :error="errors.font_size"
            narrow
          />
        </template>

        <template v-else>
          <FormField
            v-model="form.title"
            label="Title"
            placeholder='e.g., "Badge Range 1-999"'
            :error="errors.title"
          />

          <FormField
            v-model="form.subtitle"
            label="Subtitle"
            placeholder='e.g., "Free Badges"'
            :error="errors.subtitle"
          />
        </template>
      </FormSection>
    </div>
  </ManageLayout>
</template>
