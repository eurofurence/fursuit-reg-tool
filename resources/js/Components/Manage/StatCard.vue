<script setup>
/**
 * One dashboard stat: label, value, and a described comparison underneath.
 *
 * The successor to a `the old panel\Widgets\StatsOverviewWidget\Stat`. Everything it shows is
 * server-decided, tone included, exactly like a Status triple or a declared Action: the
 * card never derives a colour from a number, so the dashboard, the table and the status
 * strip cannot end up disagreeing about what "warning" looks like.
 *
 * A stat carrying a `url` is a link, which is how `Pending Approval` reaches the fursuits
 * list; the rest render as plain cards rather than as dead links.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ManageIcon from './ManageIcon.vue';
import { resolve, toneText } from './tones.js';

const props = defineProps({
  /** { key, label, value, description, icon, tone, url } from DashboardController. */
  stat: { type: Object, required: true },
});

const tone = computed(() => resolve(toneText, props.stat.tone));

// tabular-nums only where the value is a number: an event name reads worse in it.
const numeric = computed(() => typeof props.stat.value === 'number');
</script>

<template>
  <component
    :is="stat.url ? Link : 'div'"
    :href="stat.url"
    class="flex flex-col gap-1 rounded border border-hairline bg-mg-surface-2 p-3"
    :class="stat.url ? 'transition-colors hover:bg-mg-surface-3' : ''"
  >
    <span class="text-[11px] font-medium uppercase tracking-wide text-fg-3">
      {{ stat.label }}
    </span>

    <span
      class="truncate text-[22px] font-semibold leading-tight text-fg-1"
      :class="numeric ? 'tabular-nums' : ''"
      :title="String(stat.value)"
    >
      {{ stat.value }}
    </span>

    <span v-if="stat.description" class="flex items-center gap-1 text-[12px]" :class="tone">
      <ManageIcon v-if="stat.icon" :name="stat.icon" :size="13" />
      <span class="truncate">{{ stat.description }}</span>
    </span>
  </component>
</template>
