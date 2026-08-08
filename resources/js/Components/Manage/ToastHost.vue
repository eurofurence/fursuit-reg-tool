<script setup>
import ManageIcon from './ManageIcon.vue';
import { useToasts } from './useToasts.js';

const { toasts, dismissToast } = useToasts();

const tones = {
  success: { classes: 'border-state-ok/40 text-state-ok', icon: 'circle-check' },
  warning: { classes: 'border-state-warn/40 text-state-warn', icon: 'triangle-alert' },
  danger: { classes: 'border-state-danger/40 text-state-danger', icon: 'circle-x' },
};

const tone = (name) => tones[name] ?? tones.success;
</script>

<template>
  <TransitionGroup
    tag="div"
    name="toast"
    class="manage-surface pointer-events-none fixed right-4 bottom-4 z-50 flex w-80 flex-col gap-2"
  >
    <div
      v-for="toast in toasts"
      :key="toast.id"
      class="pointer-events-auto flex gap-2.5 rounded-md border bg-mg-surface-2 p-3 shadow-lg"
      :class="tone(toast.tone).classes"
      role="status"
    >
      <ManageIcon :name="tone(toast.tone).icon" :size="16" class="mt-px shrink-0" />
      <div class="min-w-0 flex-1">
        <p class="text-[13px] font-medium text-fg-1">{{ toast.title }}</p>
        <p v-if="toast.body" class="mt-0.5 text-xs leading-snug text-fg-2">{{ toast.body }}</p>
      </div>
      <button
        type="button"
        class="shrink-0 text-fg-3 transition-colors hover:text-fg-1"
        aria-label="Dismiss"
        @click="dismissToast(toast.id)"
      >
        <ManageIcon name="x" :size="14" />
      </button>
    </div>
  </TransitionGroup>
</template>

<style scoped>
/* Toasts come in from the right edge they are pinned to. A leaving toast is
   taken out of flow so the stack below it slides up under `.toast-move` instead
   of jumping once the fade finishes.

   The motion tokens carry literal fallbacks: the host is a fixed-position stack
   that can outlive the layout that declares them. */
.toast-enter-active,
.toast-leave-active {
  transition:
    opacity var(--dur-base, 220ms) var(--ease-out-expo, cubic-bezier(0.16, 1, 0.3, 1)),
    transform var(--dur-base, 220ms) var(--ease-out-expo, cubic-bezier(0.16, 1, 0.3, 1));
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateX(1.5rem);
}

.toast-leave-active {
  position: absolute;
  right: 0;
  width: 100%;
}

.toast-move {
  transition: transform var(--dur-base, 220ms) var(--ease-out-quart, cubic-bezier(0.25, 1, 0.5, 1));
}
</style>
