<script setup>
/**
 * The event-comparison bars, successor to EventComparisonChart.
 *
 * Two fixed bars, `Badges` and `Fursuits`, and one dataset per event: the selected one,
 * then the one before it when there is one. Registers only what a bar chart needs.
 */
import { BarController, BarElement, CategoryScale, Chart, Legend, LinearScale, Tooltip } from 'chart.js';
import ChartCanvas from './ChartCanvas.vue';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip);

defineProps({
  /** { heading, type, labels, datasets, options } from DashboardController. */
  chart: { type: Object, default: null },
});
</script>

<template>
  <ChartCanvas
    v-if="chart"
    type="bar"
    :heading="chart.heading"
    :labels="chart.labels"
    :datasets="chart.datasets"
    :options="chart.options"
    empty="No events to compare"
  />
</template>
