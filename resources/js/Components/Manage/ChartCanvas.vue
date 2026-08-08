<script setup>
/**
 * The chart.js lifecycle, once, for the two dashboard charts.
 *
 * ChartDoughnut and ChartBar register the controllers and elements their own type needs
 * and hand the shaped payload down here; nothing else in the panel talks to chart.js.
 * Keeping the registration in the wrappers is what keeps the bundle honest: a page that
 * only draws a doughnut does not pull in the bar controller and the two scales.
 *
 * The payload is server-shaped (heading, labels, datasets, options) and is used as given.
 * The only thing added on this side is the panel's own text and grid colour, because the
 * server has no business knowing what a token resolves to. /manage is dark-only (see
 * manage.css), so the tokens are read once at mount rather than watched.
 *
 * A poll replaces `labels` and `datasets` every 15s. That updates the existing chart in
 * place rather than tearing it down, so the animation does not restart and a hovered
 * segment keeps its tooltip.
 */
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Chart } from 'chart.js';

const props = defineProps({
  /** 'doughnut' or 'bar'. */
  type: { type: String, required: true },
  heading: { type: String, default: null },
  labels: { type: Array, default: () => [] },
  datasets: { type: Array, default: () => [] },
  /** The server's chart.js options, merged under the theme defaults below. */
  options: { type: Object, default: () => ({}) },
  /** Shown instead of the canvas when there is nothing to draw. */
  empty: { type: String, default: 'No data' },
});

const canvas = ref(null);
let chart = null;

/** `--fg-2` and friends hold a bare `R G B` triple, which rgb() takes as-is. */
const token = (name, fallback) => {
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

  return value ? `rgb(${value})` : fallback;
};

/**
 * Panel colours for the parts chart.js draws itself. The server's options are spread on
 * top, so anything it declares (legend position, beginAtZero) wins.
 */
const themedOptions = () => {
  const text = token('--fg-2', 'rgb(167, 176, 176)');
  const muted = token('--fg-3', 'rgb(115, 125, 125)');
  const grid = token('--hairline', 'rgb(34, 43, 43)');
  const surface = token('--mg-surface-3', 'rgb(29, 38, 38)');

  const base = {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 200 },
    plugins: {
      ...(props.options.plugins ?? {}),
      legend: {
        ...(props.options.plugins?.legend ?? {}),
        labels: {
          color: text,
          boxWidth: 10,
          boxHeight: 10,
          font: { size: 11 },
          ...(props.options.plugins?.legend?.labels ?? {}),
        },
      },
      tooltip: {
        backgroundColor: surface,
        titleColor: text,
        bodyColor: text,
        borderColor: grid,
        borderWidth: 1,
        ...(props.options.plugins?.tooltip ?? {}),
      },
    },
  };

  if (!props.options.scales) {
    return { ...props.options, ...base };
  }

  // Only the declared scales are themed; the server decides which axes exist.
  const scales = {};

  for (const [axis, scale] of Object.entries(props.options.scales)) {
    scales[axis] = {
      ...scale,
      ticks: { color: muted, font: { size: 11 }, ...(scale.ticks ?? {}) },
      grid: { color: grid, ...(scale.grid ?? {}) },
    };
  }

  return { ...props.options, ...base, scales };
};

const render = () => {
  if (!canvas.value) {
    return;
  }

  chart = new Chart(canvas.value, {
    type: props.type,
    data: { labels: [...props.labels], datasets: props.datasets.map((set) => ({ ...set })) },
    options: themedOptions(),
  });
};

onMounted(render);

onBeforeUnmount(() => {
  chart?.destroy();
  chart = null;
});

watch(
  () => [props.labels, props.datasets],
  async () => {
    /*
     * A poll can empty the data and fill it again, and the canvas only exists while
     * there is something to draw. Wait for the DOM to catch up before touching it,
     * rather than drawing into an element the empty branch has just removed.
     */
    if (!props.labels.length) {
      chart?.destroy();
      chart = null;

      return;
    }

    if (!chart) {
      await nextTick();
      render();

      return;
    }

    chart.data.labels = [...props.labels];
    chart.data.datasets = props.datasets.map((set) => ({ ...set }));
    chart.update();
  },
  { deep: true },
);
</script>

<template>
  <section class="flex min-w-0 flex-col rounded border border-hairline bg-mg-surface-2">
    <h2 v-if="heading" class="border-b border-hairline px-3 py-2 text-[13px] font-medium text-fg-1">
      {{ heading }}
    </h2>

    <div class="relative h-64 p-3">
      <p v-if="!labels.length" class="flex h-full items-center justify-center text-[12px] text-fg-3">
        {{ empty }}
      </p>
      <canvas v-else ref="canvas" />
    </div>
  </section>
</template>
