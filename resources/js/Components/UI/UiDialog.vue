<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useAttrs, useId, useSlots, watch } from 'vue';
import { X } from 'lucide-vue-next';

/*
 * The app's only modal. Drop-in for primevue/dialog, and what the admin panel's
 * ManageDialog is now a thin skin over.
 *
 * The only PrimeVue component in this set that is more than styling: it owns a
 * teleport, a focus trap, Escape handling and the body scroll lock. Those are
 * the parts worth reading before trusting it.
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
     * `w-[28rem] bg-mg-surface-1`, and Tailwind resolves that by CSS source
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

const panel = ref(null);
let lastFocused = null;

// Identity for the open-dialog stack below; only its topmost member reacts to
// Escape and Tab.
const instance = Symbol('UiDialog');
const OPEN_STACK = (window.__uiDialogStack ??= []);

/*
 * Nested dialogs each lock the body, so the lock is counted rather than set:
 * closing the inner one must not hand scrolling back while the outer is up.
 *
 * `locked` is what makes the count trustworthy. Deciding from `props.visible`
 * instead lets an instance release a lock it never took — a dialog mounted with
 * `visible` already true (ConfirmModal passes `:visible="show"`, and any
 * v-if-gated dialog whose flag is set) would decrement on close or unmount and
 * free some *other* dialog's lock. The counter lives on `window` and survives
 * Inertia navigation and HMR, so that leak would strand the page unscrollable.
 */
const LOCK_KEY = '__uiDialogLocks';
/*
 * The saved overflow lives beside the counter, not on the instance, because the
 * dialog that takes the first lock is usually not the one that drops the last:
 * with a nested pair, the outer saves and the inner restores. A per-instance
 * copy meant the restore wrote a value it never saved.
 */
const PREV_KEY = '__uiDialogPrevOverflow';
let locked = false;

function lockScroll() {
    if (locked) {
        return;
    }
    locked = true;

    const n = (window[LOCK_KEY] ?? 0) + 1;
    window[LOCK_KEY] = n;
    if (n === 1) {
        // Restore what the page had rather than clearing: the POS layout sets
        // its own overflow and the panel layout is h-screen overflow-hidden, so
        // blanking it would silently hand scrolling back to the body.
        window[PREV_KEY] = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    }
}

function unlockScroll() {
    if (!locked) {
        return;
    }
    locked = false;

    const n = Math.max(0, (window[LOCK_KEY] ?? 0) - 1);
    window[LOCK_KEY] = n;
    if (n === 0) {
        document.body.style.overflow = window[PREV_KEY] ?? '';
    }
}

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/*
 * Recomputed per keypress rather than cached at open, and `offsetParent` filters
 * out anything display:none: dialogs reveal fields while open (the panel's
 * rejection reason appears the moment the notification type is picked). Tab must
 * not stop on a control that is not there, and must stop on one that just was.
 */
function focusables() {
    return Array.from(panel.value?.querySelectorAll(FOCUSABLE) ?? [])
        .filter((el) => el.offsetParent !== null);
}

function close() {
    emit('update:visible', false);
}

/*
 * Backdrop dismissal, tracked press to release. A plain click handler closes the
 * dialog when a text selection that began inside it happens to end on the mask,
 * so the press has to start there too. Left button only, and only when `modal`
 * — both conditions PrimeVue applied.
 */
let pressedMask = false;

function onMaskDown(event) {
    pressedMask = event.button === 0 && event.target === event.currentTarget;
}

function onMaskUp(event) {
    const onMask = pressedMask && event.target === event.currentTarget;
    pressedMask = false;

    if (onMask && props.dismissableMask && props.modal) {
        close();
    }
}

function onKeydown(event) {
    // Only the topmost dialog reacts, or Escape would close a whole stack at
    // once and Tab would be trapped by a panel the user cannot see.
    if (OPEN_STACK[OPEN_STACK.length - 1] !== instance) {
        return;
    }

    if (event.key === 'Escape' && props.closeOnEscape && props.closable) {
        // Stopped here so an Escape meant for the dialog does not also reach a
        // page-level handler behind it, which is how a single press used to
        // clear a table's search.
        event.stopPropagation();
        close();

        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const items = focusables();
    if (items.length === 0) {
        event.preventDefault();

        return;
    }

    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;

    // Focus can sit outside the panel: a click on the backdrop or on
    // non-focusable prose parks it on <body>. From there Tab would walk the page
    // behind the overlay, so it is pulled back to the top of the dialog instead.
    if (!panel.value?.contains(active)) {
        event.preventDefault();
        first.focus();

        return;
    }

    if (event.shiftKey && active === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
}

/*
 * Escape and the tab trap are bound on `document`, not on the panel. On the
 * panel they only fire while focus is inside it, so one click on the backdrop
 * or on non-focusable prose moved focus to <body> and Escape stopped working.
 * PrimeVue binds Escape on the document for the same reason.
 */
async function open() {
    if (OPEN_STACK.includes(instance)) {
        return;
    }

    OPEN_STACK.push(instance);
    lastFocused = document.activeElement;
    // Gated the way PrimeVue gated it: a non-modal dialog leaves the page
    // scrollable unless it asks otherwise.
    if (props.modal || props.blockScroll) {
        lockScroll();
    }
    document.addEventListener('keydown', onKeydown);

    await nextTick();

    // `visible` can go true→false inside a single tick. Without this the
    // continuation would focus a panel that is already closing and emit `show`
    // after `hide`, handing the parent an inverted lifecycle.
    if (!OPEN_STACK.includes(instance)) {
        return;
    }

    // Falls back to the panel itself, which is tabindex="-1", so a dialog of
    // pure prose still has somewhere to hold focus and Escape still reaches it.
    (focusables()[0] ?? panel.value)?.focus();
    emit('show');
}

function teardown() {
    const at = OPEN_STACK.indexOf(instance);
    if (at !== -1) {
        OPEN_STACK.splice(at, 1);
    }

    document.removeEventListener('keydown', onKeydown);
    unlockScroll();
}

function hide() {
    teardown();

    // Only if the trigger is still on the page. An action that navigates away
    // leaves a detached node behind, and focusing that drops focus to <body>
    // with no way back.
    if (lastFocused?.isConnected) {
        lastFocused.focus();
    }

    lastFocused = null;
    emit('hide');
}

watch(() => props.visible, (isOpen) => {
    if (isOpen) {
        open();

        return;
    }

    hide();
});

// A dialog can be mounted with `visible` already true — ConfirmModal passes
// `:visible="show"`, and any v-if-gated dialog whose flag is set arrives open.
// The watcher above never fires for those, so they would take no lock, trap no
// focus and emit no `show`.
onMounted(() => {
    if (props.visible) {
        open();
    }
});

// Torn down while open, which is what an Inertia visit does to the whole page:
// the close branch never runs, so the listener and the lock have to be released
// here — and focus has to come back off the node that is about to be detached.
onBeforeUnmount(() => {
    if (OPEN_STACK.includes(instance)) {
        hide();
    }
});

const MASK_DEFAULT = 'p-5';

const maskRootClass = computed(() => [
    // Not overridable: without this the mask stops covering the viewport.
    'fixed inset-0 z-[1101] flex items-center justify-center',
    props.maskClass ?? [MASK_DEFAULT, props.modal ? 'bg-black/40 backdrop-blur-sm' : ''],
]);

const PANEL_DEFAULT = 'rounded-lg shadow-lg border-0 max-h-[90vh] w-[50vw] m-0 flex flex-col dark:border dark:border-surface-700 bg-surface-0 dark:bg-surface-800 text-surface-700 dark:text-surface-0/80';
const HEADER_DEFAULT = 'flex items-center justify-between shrink-0 p-6 border-t-0 rounded-tl-lg rounded-tr-lg';
const TITLE_DEFAULT = 'font-bold text-lg';
const CLOSE_DEFAULT = 'relative flex items-center justify-center w-8 h-8 mr-2 last:mr-0 rounded-full text-surface-500 dark:text-surface-0/70 bg-transparent transition duration-200 hover:text-surface-700 dark:hover:text-white/80 hover:bg-surface-100 dark:hover:bg-surface-800/80 focus:outline-none focus:ring focus:ring-primary';
const CONTENT_DEFAULT = 'px-6 pb-8 pt-0';
const FOOTER_DEFAULT = 'flex items-center justify-end shrink-0 text-right gap-2 px-6 pb-6 border-t-0 rounded-b-lg';

// `class` still appends on top of whichever base won, so a call site can pass
// panel-class for the skin and class for a one-off. `ui-dialog-panel` is the
// transition's hook and stays out of the overridable half.
const panelRootClass = computed(() => [
    props.panelClass ?? PANEL_DEFAULT,
    'ui-dialog-panel outline-none',
    attrs.class,
]);

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

/*
 * A dialog using the #header slot has no `header` string to name it, so the
 * title node is referenced instead — what PrimeVue did. The header block is
 * conditional, though, so `aria-labelledby` may only point at it when it
 * actually renders; otherwise the dialog would name itself after a missing id
 * and end up with no accessible name at all.
 */
const titleId = useId();
const hasHeader = computed(() => Boolean(props.header || slots.header || props.closable));
</script>

<template>
    <Teleport to="body">
        <Transition name="ui-dialog">
            <div v-if="visible" :class="maskRootClass" @mousedown="onMaskDown" @mouseup="onMaskUp">
                <div
                    ref="panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="hasHeader ? titleId : undefined"
                    :aria-label="hasHeader ? undefined : (header ?? undefined)"
                    tabindex="-1"
                    :class="panelRootClass"
                    :style="panelRootStyle"
                    v-bind="{ ...attrs, class: undefined, style: undefined }"
                >
                    <div v-if="hasHeader" :class="headerRootClass">
                        <h2 :id="titleId" :class="titleRootClass">
                            <slot name="header">{{ header }}</slot>
                        </h2>

                        <button
                            v-if="closable"
                            type="button"
                            :class="closeRootClass"
                            aria-label="Close"
                            @click="close"
                        >
                            <X :size="16" aria-hidden="true" />
                        </button>
                    </div>

                    <div :class="contentRootClass">
                        <slot />
                    </div>

                    <div v-if="slots.footer" :class="footerRootClass">
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* The motion tokens carry literal fallbacks, the same way ToastHost's do: this is a
   teleported overlay and can outlive the layout that declares them. */
.ui-dialog-enter-active,
.ui-dialog-leave-active {
    transition: opacity var(--dur-fast, 150ms) var(--ease-out-quart, cubic-bezier(0.25, 1, 0.5, 1));
}

.ui-dialog-enter-active .ui-dialog-panel,
.ui-dialog-leave-active .ui-dialog-panel {
    transition: transform var(--dur-fast, 150ms) var(--ease-out-quart, cubic-bezier(0.25, 1, 0.5, 1));
}

.ui-dialog-enter-from,
.ui-dialog-leave-to {
    opacity: 0;
}

.ui-dialog-enter-from .ui-dialog-panel,
.ui-dialog-leave-to .ui-dialog-panel {
    transform: translateY(4px) scale(0.99);
}

/* Operators who set "reduce motion" get the overlay with no travel and no fade; it must
   still appear instantly rather than not at all. */
@media (prefers-reduced-motion: reduce) {
    .ui-dialog-enter-active,
    .ui-dialog-leave-active,
    .ui-dialog-enter-active .ui-dialog-panel,
    .ui-dialog-leave-active .ui-dialog-panel {
        transition: none;
    }

    .ui-dialog-enter-from .ui-dialog-panel,
    .ui-dialog-leave-to .ui-dialog-panel {
        transform: none;
    }
}
</style>
