<script setup>
/**
 * DB Service, the successor to App\Filament\Pages\DbService (audit 5.3).
 *
 * One repair: badges charged the badge fee although their owner still had unused prepaid
 * entitlement for the event. Three screens, in the blade's order of precedence - the
 * result of a run, the review of a preview, or the idle button.
 *
 * Nothing here decides anything. The server has already resolved which buttons exist,
 * what the confirm dialog says and every euro figure; this file lays them out. The two
 * "clear the screen" controls (Cancel, Run again) arrive as GET links, so leaving the
 * review cannot be a request that changes data.
 *
 * The page is deliberately not scoped by the event selector in the topbar: it operates on
 * the active event, the newest by start date, exactly as the Filament page did (audit
 * landmine 123, plan 2.9). The blade said "the current event" and left the operator to
 * guess which one that was; here the event is named on screen before anything runs.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  /** The active event, or null when the database has none. */
  event: { type: Object, default: null },
  /** The preview, present only in the review state. */
  report: { type: Object, default: null },
  /** The outcome of an apply, present only on the redirect that follows one. */
  result: { type: Object, default: null },
  /** The server-declared buttons for whichever of the three states is showing. */
  actions: { type: Array, default: () => [] },
});

/** The blade's three stat cards, in its order and with its labels. */
const stats = computed(() =>
  props.report
    ? [
        { key: 'badges', label: 'Affected badges', value: props.report.affected_badge_count },
        { key: 'users', label: 'Affected users', value: props.report.affected_user_count },
        { key: 'refund', label: 'Total to refund', value: props.report.total_refund },
      ]
    : []
);

/**
 * The review table's eight header cells, verbatim, with the four right-aligned ones the
 * blade right-aligned.
 */
const columns = [
  { key: 'image', label: 'Image', align: 'left' },
  { key: 'fursuit', label: 'Fursuit', align: 'left' },
  { key: 'species', label: 'Species', align: 'left' },
  { key: 'owner', label: 'Owner', align: 'left' },
  { key: 'badges_total', label: 'Badges (event)', align: 'right' },
  { key: 'should_be_free', label: 'Should be free', align: 'right' },
  { key: 'should_be_paid', label: 'Should be paid', align: 'right' },
  { key: 'refund', label: 'Refund', align: 'right' },
];

/*
 * The blade's fallback for a row whose fursuit, species or owner is soft-deleted. The
 * placeholder image it fell back to did not exist in public/images/ until the rebuild
 * shipped it (audit landmine 50).
 */
const PLACEHOLDER = '/images/placeholder.png';

const onImageError = (event) => {
  event.target.src = PLACEHOLDER;
};
</script>

<template>
  <Head title="DB Service" />

  <ManageLayout>
    <!-- The buttons live in the section, where the blade put them, not in the header: the
         repair is one control block that reads with the copy explaining it. -->
    <PageHeader title="DB Service" subtitle="Data repairs, run by hand and logged" />

    <div class="flex-1 space-y-3 p-4">
      <p class="text-[12px] text-fg-3">
        <template v-if="event">
          This page is not scoped by the event selector. It operates on the active event,
          <span class="text-fg-2">{{ event.name }}</span
          >, which is the newest event by start date.
        </template>
        <template v-else>
          This page is not scoped by the event selector. It operates on the active event, the newest
          event by start date, and there is none.
        </template>
      </p>

      <FormSection
        title="Fix free badges"
        description="Finds badges that were charged the badge fee even though the owner had unused prepaid / free badge entitlement for the current event, converts them to free and clears the wrongly charged amount from what the owner owes. The change is logged (activity log)."
      >
        <!-- Result state. The blade showed it instead of the review, not beside it. -->
        <div v-if="result" class="space-y-2 py-1">
          <div
            v-if="result.success"
            class="rounded border border-state-ok/35 bg-state-ok/10 px-3 py-2 text-[13px] text-fg-1"
          >
            <p class="font-medium text-state-ok">Fix applied successfully.</p>
            <ul class="mt-1 list-inside list-disc text-fg-2">
              <li>Badges converted to free: {{ result.fixed_badge_count }}</li>
              <li>Users affected: {{ result.fixed_user_count }}</li>
              <li>Total refunded: {{ result.total_refunded }}</li>
            </ul>
          </div>

          <div
            v-else
            class="rounded border border-state-danger/35 bg-state-danger/10 px-3 py-2 text-[13px] text-fg-1"
          >
            <p class="font-medium text-state-danger">Fix failed.</p>
            <p class="mt-1 text-fg-2">{{ result.error }}</p>
          </div>
        </div>

        <!-- Review state. -->
        <div v-else-if="report" class="space-y-3 py-1">
          <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
            <div
              v-for="stat in stats"
              :key="stat.key"
              class="rounded border border-hairline bg-mg-surface-2 px-3 py-2"
            >
              <p class="text-[11px] font-medium uppercase tracking-wide text-fg-3">{{ stat.label }}</p>
              <p class="text-[17px] font-semibold text-fg-1">{{ stat.value }}</p>
            </div>
          </div>

          <div v-if="report.affected_badge_count > 0" class="overflow-x-auto">
            <table class="w-full border-collapse text-[13px]">
              <thead>
                <tr class="border-b border-hairline bg-mg-surface-2">
                  <th
                    v-for="column in columns"
                    :key="column.key"
                    class="h-7 px-3 text-[11px] font-medium uppercase tracking-wide text-fg-2"
                    :class="column.align === 'right' ? 'text-right' : 'text-left'"
                  >
                    {{ column.label }}
                  </th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="row in report.rows"
                  :key="row.badge_id"
                  class="border-b border-hairline/60"
                >
                  <td class="px-3 py-1.5">
                    <img
                      :src="row.image_url ?? PLACEHOLDER"
                      alt=""
                      class="h-10 w-10 rounded-full object-cover"
                      @error="onImageError"
                    />
                  </td>
                  <td class="px-3 py-1.5 text-fg-1">{{ row.fursuit ?? '—' }}</td>
                  <td class="px-3 py-1.5 text-fg-2">{{ row.species ?? '—' }}</td>
                  <td class="px-3 py-1.5 text-fg-2">{{ row.owner ?? '—' }}</td>
                  <td class="px-3 py-1.5 text-right tabular-nums text-fg-2">{{ row.badges_total }}</td>
                  <td class="px-3 py-1.5 text-right tabular-nums text-fg-2">{{ row.should_be_free }}</td>
                  <td class="px-3 py-1.5 text-right tabular-nums text-fg-2">{{ row.should_be_paid }}</td>
                  <td class="px-3 py-1.5 text-right tabular-nums text-fg-1">{{ row.refund }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- The zero case still shows its three cards, as the blade did, and says why the
               table is missing rather than leaving an empty frame. -->
          <p v-else class="text-[13px] text-state-ok">
            No wrongly-charged prepaid badges were found for the current event.
          </p>
        </div>

        <!-- Idle state. -->
        <p v-else class="py-1 text-[13px] text-fg-2">
          Nothing has run. The check reads the database and shows what it would change before
          anything is written.
        </p>

        <div v-if="actions.length" class="flex items-center gap-2 pt-2">
          <ActionButton v-for="action in actions" :key="action.name" :action="action" />
        </div>
      </FormSection>
    </div>
  </ManageLayout>
</template>
