<script setup>
/**
 * The "Columns" button and the menu of columns this table lets an operator hide.
 *
 * Lifted out of DataTable when the toolbar was merged. It was an inline popover there with
 * a bare `columnsOpen` boolean: no aria, no Escape, no outside dismissal, so it stayed open
 * behind whatever the operator clicked next. Sitting on the same row as the filter chips it
 * would have been the one popover on the bar that behaved differently from its neighbours,
 * which is the reason to move it rather than only to reparent it.
 *
 * Same mechanics as FilterAddMenu, out of usePopover, so the two triggers on opposite ends
 * of the toolbar dismiss and refocus identically.
 *
 * The visibility state itself deliberately stays in DataTable. That component owns the
 * `hidden` list and the POST to admin.tables.columns that persists it per user per table;
 * this one only draws it and says which key was clicked.
 */
import { ref } from 'vue';
import ManageIcon from './ManageIcon.vue';
import { focusItem, usePopover } from './usePopover.js';

defineProps({
  /** The toggleable columns, in declaration order. */
  columns: { type: Array, required: true },
  /** Keys currently hidden. */
  hidden: { type: Array, default: () => [] },
});

const emit = defineEmits(['toggle']);

/** 14rem, the panel's own width, so it can decide which edge to hang from. */
const { open, root, trigger, openPopover, close, toggle, onFocusOut, onOpened, alignRight } =
  usePopover({ panelWidth: 224 });

const menu = ref(null);

/*
 * The checkboxes are the focusable things here, not the labels wrapping them.
 *
 * The panel is a labelled group of checkboxes rather than FilterAddMenu's role="menu", and
 * that is a correctness point, not a preference: role="menu" obliges every child to be a
 * menuitem, and forcing menuitemcheckbox onto a native input means hand-maintaining
 * aria-checked in place of the state the input already announces. These are independent
 * switches, so they are left as what they are and Arrow/Home/End is added on top of Tab.
 */
const items = () => Array.from(menu.value?.querySelectorAll('input[type="checkbox"]') ?? []);

onOpened.value = () => focusItem(items(), 0);

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
  <div ref="root" class="relative flex-none" @focusout="onFocusOut">
    <button
      ref="trigger"
      type="button"
      class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] leading-none text-fg-2 outline-none transition-colors hover:bg-mg-surface-3 hover:text-fg-1 focus-visible:border-state-live/60 focus-visible:ring-1 focus-visible:ring-state-live/40"
      aria-haspopup="true"
      :aria-expanded="open"
      title="Show or hide columns"
      @click="toggle"
      @keydown="onTriggerKeydown"
    >
      <ManageIcon name="columns" :size="12" class="shrink-0" aria-hidden="true" />
      <span>Columns</span>
    </button>

    <!--
      max-w against the viewport for the same reason the filter popovers carry it: this
      panel hangs off the right end of a bar that wraps, and a panel wider than what is left
      of the window would hand the page the horizontal scrollbar the whole toolbar exists to
      avoid.
    -->
    <div
      v-if="open"
      ref="menu"
      class="absolute top-full z-30 mt-1 max-h-72 w-56 max-w-[calc(100vw-2rem)] overflow-y-auto rounded border border-hairline bg-mg-surface-3 p-1 shadow-lg shadow-black/40"
      :class="alignRight ? 'right-0' : 'left-0'"
      role="group"
      aria-label="Show or hide columns"
      @keydown="onMenuKeydown"
    >
      <label
        v-for="column in columns"
        :key="column.key"
        class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[12px] leading-none text-fg-2 transition-colors hover:bg-mg-surface-2 hover:text-fg-1 focus-within:bg-mg-surface-2 focus-within:text-fg-1"
      >
        <input
          type="checkbox"
          :checked="!hidden.includes(column.key)"
          class="cursor-pointer outline-none focus-visible:ring-1 focus-visible:ring-state-live/40"
          @change="emit('toggle', column.key)"
        />
        <span class="min-w-0 flex-1 truncate">{{ column.label }}</span>
      </label>
    </div>
  </div>
</template>
