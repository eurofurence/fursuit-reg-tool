<script setup>
/**
 * The global event filter, this panel's defining cross-cutting control.
 *
 * Not a port. The Filament original navigated the whole page with
 * `?selected_event_id=`, had no "All events" option in its markup, and its middleware
 * forgot the session key and re-seeded it in the same request, so the one branch that
 * meant "all events" could never be reached. Here the choice is an explicit
 * POST to manage.event.select, `null` means all events and is a value the server stores,
 * and every option carries its own orders-open marker rather than only the selected one.
 *
 * A hand-rolled listbox rather than a <select>, and rather than an off-the-shelf select
 * widget, for one reason: a native <option> can only carry text. Saying "orders open"
 * per option therefore cost eight characters of label each, which is what pushed the
 * closed trigger past its width cap and out of the 40px strip. A 6px dot on the same
 * state-ok / state-idle tokens the rest of the panel resolves through says it in no
 * width at all, so the trigger can shrink to the event's name and still lose nothing,
 * and it adds no dependency: the panel is built from its own components only.
 *
 * The trigger is short on purpose. ManageStatusStrip renders the selected event's
 * orders-open state as its own segment, so repeating it here would be the same fact
 * twice in a 40px bar.
 */
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import ManageIcon from './ManageIcon.vue';
import { resolve, toneDot } from './tones.js';

const props = defineProps({
  /** { id, name, year, orders_open, options: [{ id, name, year, orders_open }] } */
  event: { type: Object, default: null },
});

/** Disables the control while the POST is in flight, so a double choice cannot race. */
const processing = ref(false);

const open = ref(false);

/**
 * The roving position inside the popover. Focus stays on the listbox element and this
 * drives aria-activedescendant, which is the pattern that keeps Escape and Tab working
 * without moving DOM focus onto elements that are about to be unmounted.
 */
const activeIndex = ref(0);

const root = ref(null);
const trigger = ref(null);
const listbox = ref(null);

/** Stable per-instance prefix so aria-activedescendant points at a real, unique id. */
const uid = useId();

const optionId = (index) => `${uid}-option-${index}`;

/**
 * "All events" is a real option with a real value, not the absence of one: null is a
 * choice the server records. It leads the list because it is the reset, and because
 * Home then always reaches it.
 */
const options = computed(() => [
  { id: null, name: 'All events', year: null, orders_open: null },
  ...(props.event?.options ?? []),
]);

const selectedId = computed(() => props.event?.id ?? null);

const selectedIndex = computed(() => {
  const index = options.value.findIndex((option) => option.id === selectedId.value);

  // A selected event that has since been deleted resolves server-side to all events,
  // so falling back to index 0 agrees with what the server already decided.
  return index === -1 ? 0 : index;
});

const label = (option) => (option.year ? `${option.name} (${option.year})` : option.name);

const triggerLabel = computed(() => label(options.value[selectedIndex.value]));

/** null is the all-events row, which has no order window of its own to report. */
const dotTone = (option) => (option.orders_open ? 'ok' : 'idle');

const scrollActiveIntoView = () => {
  listbox.value
    ?.querySelector(`#${CSS.escape(optionId(activeIndex.value))}`)
    ?.scrollIntoView({ block: 'nearest' });
};

const openList = async (index = selectedIndex.value) => {
  if (processing.value || open.value) {
    return;
  }

  activeIndex.value = index;
  open.value = true;

  await nextTick();
  listbox.value?.focus();
  scrollActiveIntoView();
};

/**
 * Escape and choosing hand the strip back to the trigger; dismissing by clicking or
 * tabbing away must not, or focus would be yanked back from wherever the operator went.
 */
const close = ({ refocus = true } = {}) => {
  if (!open.value) {
    return;
  }

  open.value = false;

  if (refocus) {
    nextTick(() => trigger.value?.focus());
  }
};

const toggle = () => (open.value ? close() : openList());

/** Clamped rather than wrapping: Home and End are the ways to reach the ends. */
const move = (delta) => {
  const last = options.value.length - 1;

  activeIndex.value = Math.min(last, Math.max(0, activeIndex.value + delta));

  nextTick(scrollActiveIntoView);
};

const moveTo = (index) => {
  activeIndex.value = index;

  nextTick(scrollActiveIntoView);
};

const choose = (option) => {
  close();

  if (option.id === selectedId.value) {
    return;
  }

  processing.value = true;

  router.post(
    route('manage.event.select'),
    { event_id: option.id },
    {
      preserveScroll: true,
      onFinish: () => {
        processing.value = false;
      },
    },
  );
};

const onTriggerKeydown = (event) => {
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    openList();
  }
};

const onListKeydown = (event) => {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      move(1);
      break;
    case 'ArrowUp':
      event.preventDefault();
      move(-1);
      break;
    case 'Home':
      event.preventDefault();
      moveTo(0);
      break;
    case 'End':
      event.preventDefault();
      moveTo(options.value.length - 1);
      break;
    case 'Enter':
    case ' ':
      event.preventDefault();
      choose(options.value[activeIndex.value]);
      break;
    case 'Escape':
      event.preventDefault();
      close();
      break;
    default:
      break;
  }
};

/**
 * Covers Tab out as well as a click that lands on something focusable elsewhere. Tab is
 * not intercepted, so focus goes where the operator sent it and the popover simply stops
 * existing behind them.
 */
const onFocusOut = (event) => {
  if (!root.value?.contains(event.relatedTarget)) {
    close({ refocus: false });
  }
};

/**
 * Capture phase, so a click on some other control both dismisses this and still reaches
 * that control in the same gesture.
 */
const onPointerDown = (event) => {
  if (!root.value?.contains(event.target)) {
    close({ refocus: false });
  }
};

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onPointerDown, true);
  } else {
    document.removeEventListener('pointerdown', onPointerDown, true);
  }
});

onBeforeUnmount(() => document.removeEventListener('pointerdown', onPointerDown, true));
</script>

<template>
  <div ref="root" class="relative" @focusout="onFocusOut">
    <button
      ref="trigger"
      type="button"
      class="flex h-6 max-w-[11rem] items-center gap-1 rounded border border-hairline bg-mg-surface-2 px-1.5 text-[11px] font-medium text-fg-1 outline-none transition-colors hover:border-state-live/40 focus-visible:border-state-live/60 focus-visible:ring-1 focus-visible:ring-state-live/40 disabled:opacity-50"
      :disabled="processing"
      aria-haspopup="listbox"
      :aria-expanded="open"
      :aria-label="`Event filter: ${triggerLabel}`"
      :title="`Event filter: ${triggerLabel}`"
      @click="toggle"
      @keydown="onTriggerKeydown"
    >
      <span class="truncate">{{ triggerLabel }}</span>
      <ManageIcon name="chevron-down" :size="12" class="shrink-0 text-fg-3" />
    </button>

    <!--
      Anchored to the trigger's right edge and grown leftwards, because the trigger now
      sits at the right end of the strip: right-0 is the one anchor that cannot push the
      popover off the viewport.
    -->
    <ul
      v-if="open"
      ref="listbox"
      class="absolute right-0 top-full z-30 mt-1 max-h-72 min-w-[13rem] overflow-y-auto rounded border border-hairline bg-mg-surface-3 py-1 shadow-lg shadow-black/40 outline-none"
      role="listbox"
      tabindex="-1"
      aria-label="Event filter"
      :aria-activedescendant="optionId(activeIndex)"
      @keydown="onListKeydown"
    >
      <li
        v-for="(option, index) in options"
        :id="optionId(index)"
        :key="option.id ?? 'all'"
        class="flex cursor-pointer items-center gap-2 px-2 py-1 text-[12px]"
        :class="[
          index === activeIndex ? 'bg-state-live/12 text-fg-1' : 'text-fg-2',
          index === selectedIndex ? 'font-medium text-fg-1' : '',
        ]"
        role="option"
        :aria-selected="index === selectedIndex"
        @mousedown.prevent
        @click="choose(option)"
        @mousemove="activeIndex = index"
      >
        <!--
          The dot is the whole reason this is not a <select>. All events keeps an empty
          slot of the same size so the labels stay on one column.
        -->
        <span
          v-if="option.id !== null"
          class="size-1.5 shrink-0 rounded-full"
          :class="resolve(toneDot, dotTone(option))"
          aria-hidden="true"
        />
        <span v-else class="size-1.5 shrink-0" aria-hidden="true" />

        <span class="min-w-0 flex-1 truncate">{{ label(option) }}</span>

        <!--
          The orders-open state read out for anyone not seeing the dot. Visually it is
          already said by the colour, so the text is screen-reader only.
        -->
        <span v-if="option.id !== null" class="sr-only">
          {{ option.orders_open ? 'Orders open' : 'Orders closed' }}
        </span>

        <ManageIcon
          v-if="index === selectedIndex"
          name="check"
          :size="12"
          class="shrink-0 text-state-live"
        />
      </li>
    </ul>
  </div>
</template>
