<script setup>
/**
 * The badge-status doughnut (audit 6.2), successor to BadgeStatusChart.
 *
 * Registers exactly the chart.js pieces a doughnut needs, so nothing else is pulled into
 * the bundle, then leaves the lifecycle to ChartCanvas. Every label, value and colour is
 * server-decided: one stable colour per payment x fulfillment combination, in a declared
 * order, so the legend does not reshuffle under the 15s poll.
 */
import { ArcElement, Chart, DoughnutController, Legend, Tooltip } from 'chart.js';
import ChartCanvas from './ChartCanvas.vue';

Chart.register(DoughnutController, ArcElement, Legend, Tooltip);

defineProps({
  /** { heading, type, labels, datasets, options } from DashboardController. */
  chart: { type: Object, default: null },
});
</script>

<template>
  <ChartCanvas
    v-if="chart"
    type="doughnut"
    :heading="chart.heading"
    :labels="chart.labels"
    :datasets="chart.datasets"
    :options="chart.options"
    empty="No badges for this selection"
  />
</template>
