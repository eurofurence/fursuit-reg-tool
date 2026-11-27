<script setup>
import { computed, useAttrs, useSlots, watch } from 'vue';
import { X } from '@lucide/vue';
import { useScrollLock } from '@vueuse/core';
import { DialogContent, DialogPortal, DialogTitle } from 'reka-ui';
import { Dialog, DialogClose, DialogOverlay } from '@/Components/UI/dialog';
import { cn } from '@/lib/utils';

/*
 * The app's only modal. Drop-in for primevue/dialog, and what the admin panel's
 * ManageDialog is now a thin skin over.
 *
 * Built on the kit's reka-ui dialog, which owns the teleport, focus trap and
 * restore, Escape, nested stacking and the body scroll lock. This file maps the
 * PrimeVue props onto it.
 *
 * The `:pt` escape hatch is gone. Call sites that used it to paint the mask
 * (Components/POS/posDialog.js, Components/Manage/ManageDialog.vue) pass
 * `mask-class` instead — which is also how `.pos-surface` / `.manage-surface`
 * reach the overlay, since a teleport to <body> lands outside .pos and .manage
 * and would otherwise resolve the surface ramp against the public site's.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    visible: { type: Boolean, default: false },
    header: { type: String, default: null },
    modal: { type: Boolean, default: false },
    closable: { type: Boolean, default: true },
    /** Click on the backdrop closes it. PrimeVue tied this to `modal`. */
    dismissableMask: { type: Boolean, default: false },
    closeOnEscape: { type: Boolean, default: true },
    /*
     * A CSS length, not a Tailwind class, and it beats whatever width the panel
     * classes carry. An arbitrary-value class passed as a prop is only generated
     * if that exact literal also appears somewhere Tailwind scans, which is the
     * kind of coupling that breaks quietly the first time a width is computed.
     * An inline width cannot. A `style` attribute on the call site still wins.
     */
    width: { type: String, default: null },
    /*
     * Lands on the backdrop, so a teleported overlay can be re-skinned — this
     * is how `.pos-surface` / `.manage-surface` reach it. Replaces the mask's
     * look for the same reason the panel props do; only the positioning is
     * kept, since a mask that stops covering the viewport stops being a mask.
     */
    maskClass: { type: [String, Array, Object], default: null },
    /*
     * These REPLACE the section's default classes rather than adding to them,
     * which is what `:pt` did (mergeProps is off, so a `:pt` class overwrote the
     * preset's). Appending is not good enough: `class` fallthrough would leave
     * `w-[50vw] bg-surface-0 dark:bg-surface-800` fighting a call site's
     * `w-md bg-mg-surface-1`, and Tailwind resolves that by CSS source
     * order, not by the order the classes are written.
     */
    panelClass: { type: [String, Array, Object], default: null },
    headerClass: { type: [String, Array, Object], default: null },
    titleClass: { type: [String, Array, Object], default: null },
    closeClass: { type: [String, Array, Object], default: null },
    contentClass: { type: [String, Array, Object], default: null },
    footerClass: { type: [String, Array, Object], default: null },
    /** Locks the body without the rest of `modal` — see open(). */
    blockScroll: { type: Boolean, default: false },
});

const emit = defineEmits(['update:visible', 'show', 'hide']);

const slots = useSlots();
const attrs = useAttrs();

function close() {
    emit('update:visible', false);
}

// reka only locks the body for a modal dialog.
const bodyLocked = useScrollLock(typeof document === 'undefined' ? null : document.body);
watch(() => props.visible && props.blockScroll && !props.modal, (lock) => (bodyLocked.value = lock), { immediate: true });

function onEscape(event) {
    if (!props.closeOnEscape || !props.closable) {
        event.preventDefault();

        return;
    }

    // An Escape meant for the dialog must not also clear a page-level search behind it.
    event.stopPropagation();
}

// PrimeVue only dismissed on the backdrop of a modal, and never on focus moving away.
function onInteractOutside(event) {
    if (!(props.dismissableMask && props.modal)) {
        event.preventDefault();
    }
}

const MASK_DEFAULT = 'p-5';

const maskRootClass = computed(() => cn(
    'fixed inset-0 z-1101 flex items-center justify-center',
    props.maskClass ?? [MASK_DEFAULT, props.modal ? 'bg-black/40 backdrop-blur-xs' : 'pointer-events-none bg-transparent'],
));

const PANEL_DEFAULT = 'rounded-lg shadow-lg border-0 max-h-[90vh] w-[50vw] m-0 flex flex-col bg-card text-card-foreground';
const HEADER_DEFAULT = 'flex items-center justify-between shrink-0 p-6 border-t-0 rounded-tl-lg rounded-tr-lg';
const TITLE_DEFAULT = 'font-bold text-lg';
const CLOSE_DEFAULT = 'relative flex items-center justify-center w-8 h-8 mr-2 last:mr-0 rounded-full text-muted-foreground bg-transparent transition duration-200 hover:text-foreground hover:bg-accent focus:outline-hidden focus-visible:ring-3 focus-visible:ring-ring/50';
const CONTENT_DEFAULT = 'px-6 pb-8 pt-0';
const FOOTER_DEFAULT = 'flex items-center justify-end shrink-0 text-right gap-2 px-6 pb-6 border-t-0 rounded-b-lg';

// `class` still appends on top of whichever base won, so a call site can pass
// panel-class for the skin and class for a one-off. The motion classes stay out
// of the overridable half.
const panelRootClass = computed(() => cn(
    props.panelClass ?? PANEL_DEFAULT,
    'pointer-events-auto outline-hidden duration-150',
    'data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-99 data-[state=open]:slide-in-from-bottom-1',
    'data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-99 data-[state=closed]:slide-out-to-bottom-1',
    'motion-reduce:animate-none',
    attrs.class,
));

// Array form so a call site's own `style` is normalised after the width and
// therefore wins, the way `class` does.
const panelRootStyle = computed(() => (props.width ? [{ width: props.width }, attrs.style] : attrs.style));

const headerRootClass = computed(() => props.headerClass ?? HEADER_DEFAULT);
const titleRootClass = computed(() => props.titleClass ?? TITLE_DEFAULT);
const closeRootClass = computed(() => props.closeClass ?? CLOSE_DEFAULT);

const contentRootClass = computed(() => [
    props.contentClass ?? CONTENT_DEFAULT,
    'overflow-y-auto',
    slots.footer ? '' : 'rounded-bl-lg rounded-br-lg',
]);

const footerRootClass = computed(() => props.footerClass ?? FOOTER_DEFAULT);

const hasHeader = computed(() => Boolean(props.header || slots.header || props.closable));
</script>

<template>
    <Dialog :open="visible" :modal="modal" @update:open="(open) => !open && close()">
        <DialogPortal>
            <component :is="modal ? DialogOverlay : 'div'" :class="maskRootClass">
                <DialogContent
                    :class="panelRootClass"
                    :style="panelRootStyle"
                    :aria-describedby="undefined"
                    :aria-label="hasHeader ? undefined : (header ?? undefined)"
                    v-bind="{ ...attrs, class: undefined, style: undefined }"
                    @escape-key-down="onEscape"
                    @interact-outside="onInteractOutside"
                    @open-auto-focus="emit('show')"
                    @close-auto-focus="emit('hide')"
                >
                    <div v-if="hasHeader" :class="headerRootClass">
                        <DialogTitle as="h2" :class="titleRootClass">
                            <slot name="header">{{ header }}</slot>
                        </DialogTitle>

                        <DialogClose v-if="closable" :class="closeRootClass" aria-label="Close">
                            <X :size="16" aria-hidden="true" />
                        </DialogClose>
                    </div>

                    <div :class="contentRootClass">
                        <slot />
                    </div>

                    <div v-if="slots.footer" :class="footerRootClass">
                        <slot name="footer" />
                    </div>
                </DialogContent>
            </component>
        </DialogPortal>
    </Dialog>
</template>
