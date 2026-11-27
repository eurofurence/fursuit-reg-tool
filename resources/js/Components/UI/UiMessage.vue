<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { X } from '@lucide/vue';
import { Alert } from '@/Components/UI/alert';
import { MESSAGE, MESSAGE_ICONS, severityClass } from './severity.js';

/*
 * Drop-in for primevue/message, and for primevue/inlinemessage via `inline`.
 * Rendered by the kit's Alert.
 *
 * PrimeVue split these into two components that differed only in padding and in
 * whether a close button existed. They are one component here.
 *
 * Severity accepts both spellings the codebase already uses: Message called
 * them `warn`/`error`, Button and Tag called the same colours
 * `warning`/`danger`. severity.js aliases them so no call site has to change.
 */
const props = defineProps({
    severity: { type: String, default: 'info' },
    closable: { type: Boolean, default: true },
    /** A lucide icon component; an empty string hides the severity default. */
    icon: { type: [Object, Function, String], default: null },
    /** ms until it dismisses itself; 0 keeps it up. Ignored when `sticky`. */
    life: { type: Number, default: 0 },
    sticky: { type: Boolean, default: true },
    /** Compact, borderless and never closable, like the old InlineMessage. */
    inline: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const visible = ref(true);
let timer = null;

const resolvedIcon = computed(() => props.icon ?? severityClass(MESSAGE_ICONS, props.severity, 'info'));

const rootClass = computed(() => [
    severityClass(MESSAGE, props.severity, 'info'),
    // The MESSAGE table supplies border *colours*, so the inline variant has to
    // declare a border width or it renders borderless where the preset did not.
    props.inline
        ? 'inline-flex w-auto items-center justify-center align-top rounded-md p-3 gap-2 border-0'
        : 'flex items-center gap-2 my-4 mx-0 py-5 px-7 rounded-md border-0 border-l-[6px] text-base',
]);

// PrimeVue auto-dismisses only when explicitly told to; `sticky` defaults on so
// a validation message never vanishes while it is being read.
function arm() {
    clearTimeout(timer);

    if (!props.sticky && props.life > 0) {
        timer = setTimeout(close, props.life);
    }
}

function close() {
    visible.value = false;
    emit('close');
}

watch(() => [props.life, props.sticky], arm, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <transition
        enter-from-class="opacity-0"
        enter-active-class="transition-opacity duration-300"
        leave-from-class="max-h-40"
        leave-active-class="overflow-hidden transition-all duration-300 ease-in"
        leave-to-class="max-h-0 opacity-0 m-0!"
    >
        <Alert v-if="visible" :class="rootClass" aria-live="assertive" aria-atomic="true">
            <component :is="resolvedIcon" v-if="resolvedIcon" :class="[inline ? 'size-5' : 'size-6', 'shrink-0']" aria-hidden="true" />
            <span class="text-base leading-none font-medium">
                <slot />
            </span>

            <button
                v-if="closable && !inline"
                type="button"
                class="flex items-center justify-center w-8 h-8 ml-auto shrink-0 rounded-full bg-transparent transition duration-200 ease-in-out hover:bg-background/50"
                aria-label="Close"
                @click="close"
            >
                <X :size="16" aria-hidden="true" />
            </button>
        </Alert>
    </transition>
</template>
