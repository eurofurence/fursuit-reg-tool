<script setup>
import { computed, useAttrs } from 'vue';
import { OUTLINED, SOLID, TEXT, severityClass } from './severity.js';

/*
 * Drop-in for primevue/button.
 *
 * Prop names and rendered classes match the vendored Lara preset
 * (resources/js/presets/lara/button), so swapping the import is the whole
 * migration at a call site. Styling goes through the --surface-* / --primary-*
 * ramp, which means .pos and .manage keep re-skinning it by re-pointing those
 * tokens, exactly as they do for PrimeVue today.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    label: { type: String, default: null },
    icon: { type: String, default: null },
    iconPos: { type: String, default: 'left' },
    /** null | 'small' | 'large' */
    size: { type: String, default: null },
    severity: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    loadingIcon: { type: String, default: 'pi pi-spinner' },
    text: { type: Boolean, default: false },
    outlined: { type: Boolean, default: false },
    rounded: { type: Boolean, default: false },
    raised: { type: Boolean, default: false },
    link: { type: Boolean, default: false },
    plain: { type: Boolean, default: false },
    badge: { type: String, default: null },
    type: { type: String, default: 'button' },
});

const attrs = useAttrs();

// An icon-only button is square, so it has to be told apart from a labelled one
// before any padding is picked.
const iconOnly = computed(() => props.label == null && props.icon != null);

const sizeClass = computed(() => {
    // The preset applies the font size and the box separately, so an icon-only
    // button still honours `size` — it just takes its own square padding.
    const font = { small: 'text-sm', large: 'text-xl' }[props.size] ?? '';

    if (iconOnly.value) {
        return `${font} w-12 p-0 py-3`;
    }

    switch (props.size) {
        case 'small':
            return 'text-sm py-2 px-3';
        case 'large':
            return 'text-xl py-3 px-4';
        default:
            return 'px-4 py-3';
    }
});

// `plain` predates severities: it is a flat grey that ignores the ramp. It keeps
// the primary focus ring, which is what the preset does for any null severity.
const PLAIN = {
    solid: 'text-white bg-gray-500 border-gray-500 hover:bg-gray-600 hover:border-gray-600 focus:ring-primary',
    text: 'text-surface-500 hover:bg-surface-300/20 focus:ring-primary',
    outlined: 'text-surface-500 border-gray-500 hover:bg-surface-300/20 focus:ring-primary',
};

const toneClass = computed(() => {
    if (props.link) {
        return 'text-primary-600 bg-transparent border-transparent focus:ring-primary';
    }

    if (props.plain) {
        if (props.text) return `bg-transparent border-transparent ${PLAIN.text}`;
        if (props.outlined) return `bg-transparent border ${PLAIN.outlined}`;

        return `border ${PLAIN.solid}`;
    }

    if (props.text) {
        return `bg-transparent border-transparent ${severityClass(TEXT, props.severity)}`;
    }

    if (props.outlined) {
        return `bg-transparent border ${severityClass(OUTLINED, props.severity)}`;
    }

    return `border ${severityClass(SOLID, props.severity)}`;
});

const rootClass = computed(() => [
    'relative items-center inline-flex text-center align-bottom justify-center',
    'leading-[normal] transition duration-200 ease-in-out',
    'cursor-pointer overflow-hidden select-none',
    'focus:outline-none focus:outline-offset-0 focus:ring',
    sizeClass.value,
    toneClass.value,
    props.rounded ? 'rounded-full' : 'rounded-md',
    props.raised ? 'shadow-lg' : '',
    // Matches PrimeVue: a loading button is inert, not merely dimmed, so a
    // double-click cannot fire a second submit while the first is in flight.
    props.disabled || props.loading ? 'opacity-60 pointer-events-none cursor-default' : '',
    attrs.class,
]);

// Icons keep their gap only when there is a label to sit beside.
const iconClass = computed(() => [
    'mx-0',
    props.label == null ? '' : ({
        left: 'mr-2',
        right: 'ml-2 order-1',
        top: 'mb-2',
        bottom: 'mt-2',
    }[props.iconPos] ?? 'mr-2'),
]);
</script>

<template>
    <button
        :type="type"
        :class="rootClass"
        :disabled="disabled || loading"
        v-bind="{ ...attrs, class: undefined }"
    >
        <slot>
            <span v-if="loading" :class="[iconClass, 'h-4 w-4 animate-spin', loadingIcon]" aria-hidden="true" />
            <span v-else-if="icon" :class="[iconClass, icon]" aria-hidden="true" />

            <!-- Kept in the tree even when empty: PrimeVue does the same, and it
                 is what holds an icon-only button at its full height. -->
            <span :class="['duration-200 font-bold', link ? 'hover:underline' : '', label == null ? 'invisible w-0' : 'flex-1']">{{ label }}</span>

            <span
                v-if="badge"
                class="ml-2 w-4 h-4 leading-none flex items-center justify-center rounded-full bg-primary-inverse text-primary text-xs"
            >
                {{ badge }}
            </span>
        </slot>
    </button>
</template>
