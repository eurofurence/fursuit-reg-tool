<script setup>
import { computed, useAttrs } from 'vue';
import { LoaderCircle } from '@lucide/vue';
import { Button } from '@/Components/UI/button';
import { cn } from '@/lib/utils';
import { OUTLINED, SOLID, TEXT, severityClass } from './severity.js';

/*
 * Drop-in for primevue/button, rendered by the kit's Button.
 *
 * The PrimeVue props pick the kit variant and size; severity.js then paints the
 * tone on top through cn(), so a severity wins over the variant's own colours.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    label: { type: String, default: null },
    /** A lucide icon component. */
    icon: { type: [Object, Function], default: null },
    iconPos: { type: String, default: 'left' },
    /** null | 'small' | 'large' */
    size: { type: String, default: null },
    severity: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
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
// before any size is picked.
const iconOnly = computed(() => props.label == null && props.icon != null);

const kitSize = computed(() => {
    if (iconOnly.value) {
        return { small: 'icon-sm', large: 'icon-lg' }[props.size] ?? 'icon';
    }

    return { small: 'sm', large: 'lg' }[props.size] ?? 'default';
});

const kitVariant = computed(() => {
    if (props.link) return 'link';
    if (props.text) return 'ghost';
    if (props.outlined) return 'outline';

    return { secondary: 'secondary', danger: 'destructive' }[props.severity] ?? 'default';
});

// `plain` predates severities: a flat grey that ignores the ramp.
const PLAIN = {
    solid: 'text-white bg-grays-400 border-grays-400 hover:bg-grays-500 hover:border-grays-500',
    text: 'text-muted-foreground hover:bg-muted',
    outlined: 'text-muted-foreground border-grays-400 hover:bg-muted',
};

const toneClass = computed(() => {
    if (props.link) {
        return 'text-primary';
    }

    if (props.plain) {
        if (props.text) return PLAIN.text;
        if (props.outlined) return `bg-transparent ${PLAIN.outlined}`;

        return `border ${PLAIN.solid}`;
    }

    if (props.text) {
        return severityClass(TEXT, props.severity);
    }

    if (props.outlined) {
        return `bg-transparent ${severityClass(OUTLINED, props.severity)}`;
    }

    return `border ${severityClass(SOLID, props.severity)}`;
});

const rootClass = computed(() => cn(
    'relative',
    toneClass.value,
    props.size === 'large' && !iconOnly.value ? 'h-12 px-6 text-base' : '',
    ['top', 'bottom'].includes(props.iconPos) && !iconOnly.value ? 'flex-col h-auto py-2' : '',
    props.rounded ? 'rounded-full' : '',
    props.raised ? 'shadow-lg' : '',
    // Matches PrimeVue: a loading button is inert, not merely dimmed, so a
    // double-click cannot fire a second submit while the first is in flight.
    props.loading ? 'opacity-60 pointer-events-none' : '',
    attrs.class,
));

// The kit spaces icon and label with gap-2; iconPos only reorders and stacks.
const iconClass = computed(() => (['right', 'bottom'].includes(props.iconPos) ? 'order-1' : ''));
</script>

<template>
    <Button
        :type="type"
        :variant="kitVariant"
        :size="kitSize"
        :class="rootClass"
        :disabled="disabled || loading"
        v-bind="{ ...attrs, class: undefined }"
    >
        <slot>
            <LoaderCircle v-if="loading" :class="[iconClass, 'animate-spin']" aria-hidden="true" />
            <component :is="icon" v-else-if="icon" :class="iconClass" aria-hidden="true" />

            <span v-if="label != null" :class="link ? 'hover:underline' : ''">{{ label }}</span>

            <span
                v-if="badge"
                class="ml-1 w-4 h-4 leading-none flex items-center justify-center rounded-full bg-primary-foreground text-primary text-xs"
            >
                {{ badge }}
            </span>
        </slot>
    </Button>
</template>
