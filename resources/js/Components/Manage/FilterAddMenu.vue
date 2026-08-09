<script setup>
/**
 * The "Filter" button and the menu of filters this table declares but is not showing yet.
 *
 * This is the half of the Shopify model that keeps the bar empty until an operator asks
 * for something. The list is whatever the server declared minus whatever is already on
 * the bar, so a module that adds a filter appears here with no client change at all.
 *
 * role="menu" with real focus moving between the items, rather than the
 * aria-activedescendant listbox EventSelector uses: the items here are actions that add a
 * chip, not values being selected, and there is no selected state to announce. The
 * dismissal mechanics are the same ones, out of usePopover, so both behave alike.
 */
import { computed, ref } from 'vue';
import ManageIcon from './ManageIcon.vue';
import { focusItem, usePopover } from './usePopover.js';

const props = defineProps({
  /** Filters not currently on the bar, in the order the module declared them. */
  available: { type: Array, default: () => [] },
});

const emit = defineEmits(['add']);

/** 13rem, the menu's own width, so it can decide which edge to hang from. */
const { open, root, trigger, openPopover, close, toggle, onFocusOut, onOpened, alignRight } =
  usePopover({ panelWidth: 208 });

const menu = ref(null);

const items = () => Array.from(menu.value?.querySelectorAll('[role="menuitem"]') ?? []);

onOpened.value = () => focusItem(items(), 0);

const empty = computed(() => props.available.length === 0);

const choose = (filter) => {
  close();
  emit('add', filter);
};

const onTriggerKeydown = (event) => {
  if (event.key !== 'ArrowDown' && event.key !== 'Enter' && event.key !== ' ') {
    return;
  }

  event.preventDefault();
  openPopover();
};

const onMenuKeydown = (event) => {
  const list = items();
  const index = list.indexOf(document.activeElement);

  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      focusItem(list, index + 1);
      break;
    case 'ArrowUp':
      event.preventDefault();
      focusItem(list, index - 1);
      break;
    case 'Home':
      event.preventDefault();
      focusItem(list, 0);
      break;
    case 'End':
      event.preventDefault();
      focusItem(list, list.length - 1);
      break;
    case 'Escape':
      event.preventDefault();
      close();
      break;
    default:
      break;
  }
};
</script>

<template>
  <div ref="root" class="relative" @focusout="onFocusOut">
    <button
      ref="trigger"
      type="button"
      class="inline-flex h-7 items-center gap-1.5 rounded-full border border-dashed border-hairline bg-transparent px-2.5 text-[12px] leading-none text-fg-2 outline-none transition-colors hover:border-state-live/40 hover:text-fg-1 focus-visible:border-state-live/60 focus-visible:ring-1 focus-visible:ring-state-live/40 disabled:opacity-40"
      :disabled="empty"
      aria-haspopup="menu"
      :aria-expanded="open"
      :title="empty ? 'Every filter is already on the bar' : 'Add a filter'"
      @click="toggle"
      @keydown="onTriggerKeydown"
    >
      <ManageIcon name="plus" :size="12" class="shrink-0" aria-hidden="true" />
      <span>Filter</span>
    </button>

    <div
      v-if="open"
      ref="menu"
      class="absolute top-full z-30 mt-1 max-h-72 w-52 max-w-[calc(100vw-2rem)] overflow-y-auto rounded border border-hairline bg-mg-surface-3 p-1 shadow-lg shadow-black/40"
      :class="alignRight ? 'right-0' : 'left-0'"
      role="menu"
      aria-label="Add a filter"
      @keydown="onMenuKeydown"
    >
      <button
        v-for="filter in available"
        :key="filter.key"
        type="button"
        role="menuitem"
        class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-[12px] leading-none text-fg-2 outline-none transition-colors hover:bg-mg-surface-2 hover:text-fg-1 focus-visible:bg-mg-surface-2 focus-visible:text-fg-1 focus-visible:ring-1 focus-visible:ring-state-live/40"
        @click="choose(filter)"
      >
        <span class="min-w-0 flex-1 truncate">{{ filter.label }}</span>
      </button>
    </div>
  </div>
</template>
