<script setup>
import { computed } from 'vue';
import { Badge } from '@/Components/UI/badge';
import { TAG, severityClass } from './severity.js';

/*
 * Drop-in for primevue/tag, rendered by the kit's Badge.
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
    'border-transparent font-bold px-2 py-1',
    props.rounded ? 'rounded-full' : 'rounded-md',
    severityClass(TAG, props.severity),
]);
</script>

<template>
    <Badge :class="rootClass">
        <span v-if="icon" :class="['text-sm', icon]" aria-hidden="true" />
        <span class="leading-normal">
            <slot>{{ value }}</slot>
        </span>
    </Badge>
</template>
