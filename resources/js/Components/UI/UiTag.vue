<script setup>
import { computed } from 'vue';
import { TAG, severityClass } from './severity.js';

/*
 * Drop-in for primevue/tag.
 *
 * One deliberate difference: PrimeVue's Tag preset defined only primary,
 * success, info, warning and danger, so `severity="secondary"` — which
 * Pages/POS/Badges/Index.vue passes for a picked-up badge — rendered with no
 * background at all. severity.js fills in secondary, help and contrast.
 */
const props = defineProps({
    value: { type: [String, Number], default: null },
    severity: { type: String, default: null },
    icon: { type: String, default: null },
    rounded: { type: Boolean, default: false },
});

const rootClass = computed(() => [
    'text-xs font-bold inline-flex items-center justify-center px-2 py-1',
    props.rounded ? 'rounded-full' : 'rounded-md',
    severityClass(TAG, props.severity),
]);
</script>

<template>
    <span :class="rootClass">
        <span v-if="icon" :class="['mr-1 text-sm', icon]" aria-hidden="true" />
        <span class="leading-normal">
            <slot>{{ value }}</slot>
        </span>
    </span>
</template>
