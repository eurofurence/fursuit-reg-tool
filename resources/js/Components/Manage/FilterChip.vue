<script setup>
/**
 * One applied filter, as a pill with its own dropdown.
 *
 * The chip is the unit of the Shopify model the bar now follows: a filter is not on the
 * page until it is added, and once it is, it can be changed or taken off on its own
 * without touching the others. Everything the chip needs comes from the server's filter
 * declaration, so a module that declares a filter gets a chip for it and nothing else is
 * written.
 *
 * Two buttons, not one. The pill opens the editor; the cross removes the filter. They are
 * separate controls because they are separate intents, and because a cross that is only a
 * region of a larger button is unreachable by keyboard.
 *
 * A boolean filter has no editor at all: it is on because it is on the bar. It renders as
 * a plain pill with only the remove control, which is also why it does not claim
 * aria-haspopup.
 */
import { computed, onMounted, ref } from 'vue';
import ManageIcon from './ManageIcon.vue';
import FilterValueEditor from './FilterValueEditor.vue';
import { emptyValue, isActive, summarize } from './filterValue.js';
import { usePopover } from './usePopover.js';

const props = defineProps({
  /** One entry of the server's `filters` envelope, value included. */
  filter: { type: Object, required: true },
  /** Opens the editor as soon as the chip appears, for a chip just added from the menu. */
  autoOpen: { type: Boolean, default: false },
});

const emit = defineEmits(['update', 'remove']);

/** 14rem, the panel's own width, so it can decide which edge to hang from. */
const { open, root, trigger, openPopover, close, toggle, onFocusOut, onOpened, alignRight } =
  usePopover({ panelWidth: 224 });

const panel = ref(null);

/**
 * One focus rule for every editor type: the first control in the panel takes focus when
 * the panel appears, so the operator carries on from the keyboard without hunting.
 */
onOpened.value = () => panel.value?.querySelector('input, select, textarea, button')?.focus();

const applied = computed(() => isActive(props.filter));
const summary = computed(() => summarize(props.filter));

const chipLabel = computed(() => props.filter.chipLabel ?? props.filter.label);

/** Read out in full, because the pill itself abbreviates. */
const description = computed(() =>
  applied.value ? `${props.filter.label}: ${summary.value}` : `${props.filter.label}: any value`,
);

const onKeydown = (event) => {
  if (event.key === 'Escape' && open.value) {
    event.preventDefault();
    event.stopPropagation();
    close();
  }
};

const onTriggerKeydown = (event) => {
  if (event.key !== 'ArrowDown' && event.key !== 'Enter' && event.key !== ' ') {
    return;
  }

  event.preventDefault();

  if (open.value) {
    close();
  } else {
    openPopover();
  }
};

onMounted(() => {
  if (props.autoOpen && props.filter.type !== 'boolean') {
    openPopover();
  }
});

/**
 * A single-choice editor closes on choosing, the way a menu does. A multi-select and the
 * free-value editors stay open, because the operator is usually not finished after one
 * checkbox or one keystroke.
 */
const onUpdate = (value) => {
  emit('update', value);

  if (props.filter.type === 'select' && !props.filter.multiple) {
    close();
  }

  if (props.filter.type === 'ternary') {
    close();
  }
};

const pill =
  'inline-flex h-7 max-w-full items-center rounded-full border text-[12px] leading-none transition-colors';
</script>

<template>
  <div ref="root" class="relative" @focusout="onFocusOut" @keydown="onKeydown">
    <div
      :class="[
        pill,
        applied
          ? 'border-state-live/40 bg-state-live/10 text-state-live'
          : 'border-hairline bg-mg-surface-2 text-fg-2',
      ]"
    >
      <button
        v-if="filter.type !== 'boolean'"
        ref="trigger"
        type="button"
        class="flex min-w-0 items-center gap-1.5 rounded-full py-1 pl-2.5 pr-1.5 outline-none focus-visible:ring-1 focus-visible:ring-state-live/60"
        aria-haspopup="dialog"
        :aria-expanded="open"
        :aria-label="description"
        :title="description"
        @click="toggle"
        @keydown="onTriggerKeydown"
      >
        <span class="shrink-0 font-medium">{{ chipLabel }}</span>

        <span v-if="summary" class="min-w-0 truncate opacity-90">{{ summary }}</span>
        <span v-else class="shrink-0 text-fg-3">any</span>

        <ManageIcon name="chevron-down" :size="12" class="shrink-0 opacity-70" aria-hidden="true" />
      </button>

      <!-- A boolean is on because it is on the bar, so there is nothing to open. -->
      <span v-else class="flex min-w-0 items-center py-1 pl-2.5 pr-1">
        <span class="min-w-0 truncate font-medium">{{ chipLabel }}</span>
      </span>

      <!--
        Pinned filters are the module's own furniture: they stay on the bar and are
        emptied rather than taken off, so they offer no cross. Their editor's "any" row
        is the way back to unfiltered. A boolean keeps its cross either way, because it
        has no editor and the cross is the only way to switch it off.
      -->
      <button
        v-if="!filter.pinned || filter.type === 'boolean'"
        type="button"
        class="mr-1 flex size-5 shrink-0 items-center justify-center rounded-full outline-none transition-colors hover:bg-state-live/20 focus-visible:ring-1 focus-visible:ring-state-live/60"
        :aria-label="`Remove ${filter.label} filter`"
        :title="`Remove ${filter.label} filter`"
        @click="emit('remove')"
      >
        <ManageIcon name="x" :size="12" aria-hidden="true" />
      </button>
    </div>

    <div
      v-if="open"
      ref="panel"
      class="absolute top-full z-30 mt-1 max-h-72 w-56 max-w-[calc(100vw-2rem)] overflow-y-auto rounded border border-hairline bg-mg-surface-3 p-2 shadow-lg shadow-black/40"
      :class="alignRight ? 'right-0' : 'left-0'"
      role="dialog"
      :aria-label="`Filter by ${filter.label}`"
    >
      <FilterValueEditor :filter="filter" @update="onUpdate" />

      <!--
        Empties the filter without taking the chip off the bar. The choice editors say the
        same thing with their "any" row, but a range or a free value has no such row, and
        a pinned chip has no cross either, so this is the only way back to unfiltered for
        those two.
      -->
      <button
        v-if="applied"
        type="button"
        class="mt-1.5 w-full rounded px-2 py-1 text-left text-[11px] leading-none text-fg-3 outline-none transition-colors hover:bg-mg-surface-2 hover:text-fg-1 focus-visible:ring-1 focus-visible:ring-state-live/40"
        @click="onUpdate(emptyValue(filter))"
      >
        Clear
      </button>
    </div>
  </div>
</template>
