<script setup>
/**
 * The panel's modal: UiDialog wearing the mg-* tokens.
 *
 * The behaviour (teleport, focus trap, Escape, backdrop dismissal, the counted
 * body-scroll lock) lives in UiDialog and is shared with the POS and the public site.
 * All that is left here is the skin plus two defaults, and that is the whole point of
 * the file: the three call sites it serves (ActionButton's confirm, Fursuits/Show's two
 * moderation dialogs, Machines/Form's login link) would otherwise each repeat the same
 * eight class props, and a skin copied four times is a skin that drifts.
 *
 * The class props REPLACE UiDialog's defaults rather than adding to them, because
 * `bg-surface-0 w-[50vw]` would otherwise fight the panel's own classes with the winner
 * decided by CSS source order rather than by what is written here.
 *
 * `mask-class` is how `manage-surface` reaches the overlay at all: it carries the
 * panel's 13px/18px base, the mg-num / mg-mono / mg-label rules and color-scheme: dark,
 * and those rules are scoped to the panel subtree while a teleport to <body> lands
 * outside it. The mg-* colour tokens themselves are on :root and reach here on their own.
 */
import UiDialog from '@/Components/UI/UiDialog.vue';

defineProps({
  visible: { type: Boolean, default: false },
  /** Names the dialog. Rendered as the heading and referenced by aria-labelledby. */
  header: { type: String, default: null },
  /*
   * A CSS length, not a Tailwind class. The call sites want 28rem and 32rem, and an
   * arbitrary-value class passed as a prop is only generated if that exact literal also
   * appears somewhere Tailwind scans, which is the kind of coupling that breaks quietly
   * the first time a width is computed. An inline width cannot.
   */
  width: { type: String, default: '28rem' },
  /** Off for a dialog whose work must not be lost by a stray click on the backdrop. */
  dismissableMask: { type: Boolean, default: true },
});

defineEmits(['update:visible']);
</script>

<template>
  <UiDialog
    :visible="visible"
    :header="header"
    :width="width"
    :dismissable-mask="dismissableMask"
    modal
    mask-class="manage-surface bg-black/50 p-5"
    panel-class="m-0 flex max-h-[90vh] max-w-full flex-col rounded-md border border-hairline bg-mg-surface-1 text-fg-1 shadow-xl"
    header-class="flex shrink-0 items-center justify-between gap-3 border-b border-hairline px-4 py-3"
    title-class="text-base font-medium text-fg-1"
    close-class="inline-flex size-6 items-center justify-center rounded text-fg-3 transition-colors hover:bg-mg-surface-3 hover:text-fg-1 focus-visible:outline focus-visible:outline-1 focus-visible:outline-offset-2 focus-visible:outline-state-live"
    content-class="flex flex-col gap-3 px-4 py-3"
    footer-class="flex shrink-0 items-center justify-end gap-2 border-t border-hairline px-4 py-3"
    @update:visible="$emit('update:visible', $event)"
  >
    <!-- Both slots are passed conditionally: UiDialog renders the footer bar whenever a
         footer slot exists, so an unconditional forward would give the login-link dialog,
         which has no footer, an empty bordered strip. -->
    <template v-if="$slots.header" #header>
      <slot name="header" />
    </template>

    <slot />

    <template v-if="$slots.footer" #footer>
      <slot name="footer" />
    </template>
  </UiDialog>
</template>
