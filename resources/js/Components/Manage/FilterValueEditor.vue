<script setup>
/**
 * The inside of a filter chip's popover: one editor per declared filter type.
 *
 * This is the extension point. A module adds a filter by declaring it in its Table, and
 * the only reason it ever reaches the client is that this file already knows the type.
 * Adding a type means one branch here and one in App\Support\Manage\Filter, not a new
 * control hand-rolled on a page - which is what the checkout, badge and print-job lists
 * were each doing before.
 *
 * Controls are native inputs on purpose. Radios and checkboxes come with arrow-key and
 * space semantics, label association and the panel's own checkbox styling; a div with
 * role="option" would be a reimplementation of all three. They carry no colour classes
 * here: the panel styles its form controls in one place.
 *
 * Values are emitted whole. The editor never writes to the URL itself, so the chip stays
 * the single place that knows how a filter is applied and removed. It does not manage
 * focus either: the chip focuses the first control in its panel once, from the outside,
 * so there is one focus rule for every type rather than one per branch.
 */
import { computed, ref, useId, watch } from 'vue';

const props = defineProps({
  /** One entry of the server's `filters` envelope, value included. */
  filter: { type: Object, required: true },
});

const emit = defineEmits(['update']);

/** Distinct radio groups per chip, or two chips on one bar would share a selection. */
const uid = useId();

/**
 * Free-value types keep a local draft so the operator can type: writing every keystroke
 * to the URL would re-render the bar under their hands. The draft follows the server
 * again whenever a reload lands, so a poll cannot leave the box saying one thing while
 * the list shows another.
 */
const draft = ref('');
const bounds = ref({ min: '', max: '' });

/**
 * Whether the operator is still working in this editor: the box has focus, or a debounced
 * commit has not gone out yet.
 *
 * This is what stops the sync below from typing over them. Committing reloads `filters`, so
 * every commit hands back a fresh value while the next keystrokes are already in the box - and
 * a naive sync then rewrites "1234" to the "12" the server was told about half a second ago.
 * That was the "it resets while I type" bug: fast typing lost the tail, slow typing had each
 * debounce land on top of the next word.
 */
const editing = ref(false);
const focused = ref(false);

watch(
  () => props.filter.value,
  (value) => {
    // While they are typing, the box is the truth and the server is behind. It catches up on
    // blur, which commits and releases the guard.
    if (editing.value || focused.value) {
      return;
    }

    draft.value = typeof value === 'string' ? value : '';
    bounds.value = { min: value?.min ?? '', max: value?.max ?? '' };
  },
  { immediate: true },
);

let debounce = null;

const commit = (value) => {
  window.clearTimeout(debounce);
  editing.value = false;
  emit('update', value);
};

/** Typing settles before the visit goes out; Enter and blur commit at once. */
const commitLater = (value) => {
  window.clearTimeout(debounce);
  editing.value = true;
  debounce = window.setTimeout(() => {
    editing.value = false;
    emit('update', value);
  }, 400);
};

const onFocus = () => {
  focused.value = true;
};

/**
 * Leaving the field ends the edit and sends whatever is in it, rather than waiting out the
 * remaining debounce: clicking from "From" to "To" and typing has to apply the first bound,
 * and a popover closed on the click outside would otherwise drop it entirely.
 */
const onBlur = (value) => {
  focused.value = false;

  if (editing.value) {
    commit(value);
  }
};

const selected = computed(() => props.filter.value);

const isChecked = (option) => (selected.value ?? []).includes(option.value);

const toggle = (option) => {
  const set = selected.value ?? [];

  commit(set.includes(option.value) ? set.filter((item) => item !== option.value) : [...set, option.value]);
};

/**
 * The "any value" row every choice editor carries. It empties the filter without taking
 * the chip off the bar, which is the difference between "show me all statuses" and "I am
 * done with this filter" - the chip's own Remove is the second one.
 */
const anyLabel = computed(() => props.filter.placeholder ?? `Any ${props.filter.label.toLowerCase()}`);

/** The native control behind the free-value branch, one per free-value type. */
const inputType = computed(
  () =>
    ({ number: 'number', date: 'date', datetime: 'datetime-local' })[props.filter.type] ?? 'text',
);

const ternaryOptions = computed(() => [
  { value: '1', label: props.filter.trueLabel ?? 'Yes' },
  { value: '0', label: props.filter.falseLabel ?? 'No' },
]);

const row =
  'flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[12px] leading-none text-fg-2 hover:bg-mg-surface-2 has-[:checked]:text-fg-1';

const field =
  'h-7 w-full rounded border border-hairline bg-mg-surface-2 px-2 text-[12px] text-fg-1 outline-none focus-visible:border-state-live/60 focus-visible:ring-1 focus-visible:ring-state-live/40';
</script>

<template>
  <!-- Multi choice. -->
  <div v-if="filter.type === 'select' && filter.multiple" class="flex flex-col gap-0.5">
    <label v-for="option in filter.options" :key="option.value" :class="row">
      <input type="checkbox" :checked="isChecked(option)" @change="toggle(option)" />
      <span class="min-w-0 flex-1 truncate">{{ option.label }}</span>
    </label>
  </div>

  <!-- Single choice, and the three-state ternary, which is the same control with two
       fixed options rather than a declared list. -->
  <div v-else-if="filter.type === 'select' || filter.type === 'ternary'" class="flex flex-col gap-0.5">
    <label :class="row">
      <input
        type="radio"
        :name="uid"
        :checked="!filter.value"
        @change="commit('')"
      />
      <span class="min-w-0 flex-1 truncate text-fg-3">{{ anyLabel }}</span>
    </label>

    <label
      v-for="option in filter.type === 'ternary' ? ternaryOptions : filter.options"
      :key="option.value"
      :class="row"
    >
      <input
        type="radio"
        :name="uid"
        :checked="String(filter.value) === String(option.value)"
        @change="commit(option.value)"
      />
      <span class="min-w-0 flex-1 truncate">{{ option.label }}</span>
    </label>
  </div>

  <!-- Two bounds, either of which may be left blank. -->
  <div v-else-if="filter.type === 'range'" class="flex items-center gap-2">
    <label class="flex flex-1 flex-col gap-1">
      <span class="text-[10px] uppercase tracking-wide text-fg-3">From</span>
      <input
        v-model="bounds.min"
        type="number"
        inputmode="numeric"
        :class="field"
        @focus="onFocus"
        @blur="onBlur({ ...bounds })"
        @input="commitLater({ ...bounds })"
        @keydown.enter.prevent="commit({ ...bounds })"
      />
    </label>

    <label class="flex flex-1 flex-col gap-1">
      <span class="text-[10px] uppercase tracking-wide text-fg-3">To</span>
      <input
        v-model="bounds.max"
        type="number"
        inputmode="numeric"
        :class="field"
        @focus="onFocus"
        @blur="onBlur({ ...bounds })"
        @input="commitLater({ ...bounds })"
        @keydown.enter.prevent="commit({ ...bounds })"
      />
    </label>
  </div>

  <!-- One free value: text, number or date. -->
  <label v-else class="flex flex-col gap-1">
    <span class="sr-only">{{ filter.label }}</span>
    <input
      v-model="draft"
      :type="inputType"
      :placeholder="filter.placeholder ?? filter.label"
      :class="field"
      @focus="onFocus"
      @blur="onBlur(draft)"
      @input="commitLater(draft)"
      @change="commit(draft)"
      @keydown.enter.prevent="commit(draft)"
    />
  </label>
</template>
