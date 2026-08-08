<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { MESSAGE, MESSAGE_ICONS, severityClass } from './severity.js';

/*
 * Drop-in for primevue/message, and for primevue/inlinemessage via `inline`.
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
    icon: { type: String, default: null },
    /** ms until it dismisses itself; 0 keeps it up. Ignored when `sticky`. */
    life: { type: Number, default: 0 },
    sticky: { type: Boolean, default: true },
    /** Compact, borderless, never closable — the old InlineMessage. */
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
        ? 'inline-flex items-center justify-center align-top rounded-md p-3 gap-2 border-0 dark:border dark:border-solid'
        : 'my-4 mx-0 rounded-md border-solid border-0 border-l-[6px]',
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
        leave-to-class="max-h-0 opacity-0 !m-0"
    >
        <div v-if="visible" :class="rootClass" role="alert" aria-live="assertive" aria-atomic="true">
            <div v-if="inline" class="contents">
                <span v-if="resolvedIcon" :class="['text-lg leading-none shrink-0', resolvedIcon]" aria-hidden="true" />
                <span class="text-base leading-none font-medium">
                    <slot />
                </span>
            </div>

            <div v-else class="flex items-center py-5 px-7">
                <span v-if="resolvedIcon" :class="['w-6 h-6 text-lg leading-none mr-2 shrink-0', resolvedIcon]" aria-hidden="true" />
                <span class="text-base leading-none font-medium">
                    <slot />
                </span>

                <button
                    v-if="closable"
                    type="button"
                    class="flex items-center justify-center w-8 h-8 ml-auto relative rounded-full bg-transparent transition duration-200 ease-in-out hover:bg-surface-0/50 dark:hover:bg-surface-0/10 overflow-hidden"
                    aria-label="Close"
                    @click="close"
                >
                    <span class="pi pi-times" aria-hidden="true" />
                </button>
            </div>
        </div>
    </transition>
</template>
