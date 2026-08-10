<script setup>
/**
 * The dashboard: four stats, the badge-status doughnut and the event
 * comparison bars, everything scoped to the header's event selection.
 *
 * Nothing here computes a number, a colour or a label. DashboardController ships the
 * stats and both charts shaped, the way every action and column in the panel is shaped
 * server-side, so a parity test can assert a string without rendering a canvas.
 *
 * The poll reloads `stats` and `charts` and nothing else. 15s, not the 5s all
 * three widgets inherited from CanPoll: an open dashboard used to re-run four counts and
 * a GROUP BY over the whole badges table twelve times a minute per tab.
 * The poll stops while the tab is hidden, and this page has no form or dialog to guard.
 */
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { usePagePoll } from '@/Components/Manage/usePagePoll.js';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ChartBar from '@/Components/Manage/ChartBar.vue';
import ChartDoughnut from '@/Components/Manage/ChartDoughnut.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatCard from '@/Components/Manage/StatCard.vue';

defineProps({
  /** Four { key, label, value, description, icon, tone, url } cards, in audit order. */
  stats: { type: Array, default: () => [] },
  /** { badgeStatus, eventComparison }, each a shaped chart.js payload. */
  charts: { type: Object, default: () => ({}) },
});

const page = usePage();

usePagePoll(15000, { only: ['stats', 'charts'] });

const scope = computed(() => {
  const event = page.props.manageEvent;

  return event?.id ? `${event.name} (${event.year})` : 'All events';
});
</script>

<template>
  <Head title="Dashboard" />

  <ManageLayout>
    <PageHeader title="Dashboard" :subtitle="scope" />

    <div class="flex-1 space-y-3 p-4">
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard v-for="stat in stats" :key="stat.key" :stat="stat" />
      </div>

      <!-- Bars first, doughnut second: the widgets sorted 2 then 3. -->
      <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <ChartBar :chart="charts.eventComparison" />
        <ChartDoughnut :chart="charts.badgeStatus" />
      </div>
    </div>
  </ManageLayout>
</template>
