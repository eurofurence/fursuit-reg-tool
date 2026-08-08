<script setup>
/**
 * One field, laid out as a row: label on the left, control on the right.
 *
 * Two-column forms fit more per screen but make an operator's eye jump; a single column of
 * label/control rows reads straight down and keeps every helper text directly under the
 * thing it explains. The label column is a fixed width so controls line up down the form.
 *
 * A read-only field renders as text rather than a disabled input: Filament used
 * Placeholder for exactly these (created_at, badge totals) and a greyed-out box invites
 * clicking something that can never change.
 */
defineProps({
  label: { type: String, required: true },
  modelValue: { type: [String, Number, Boolean, null], default: null },
  type: { type: String, default: 'text' },
  /** [{ value, label }] for type="select" */
  options: { type: Array, default: () => [] },
  helper: { type: String, default: null },
  error: { type: String, default: null },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
  placeholder: { type: String, default: null },
  min: { type: [String, Number], default: null },
  max: { type: [String, Number], default: null },
  step: { type: [String, Number], default: null },
  mono: { type: Boolean, default: false },
  /** Cap the control width for fields whose content is always short. */
  narrow: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

/*
 * No vertical padding and no line-height on purpose, for the select in particular:
 * @tailwindcss/forms leaves .5rem of padding-block and a 1.5rem line on every select in
 * the app, which is what used to push the label below the middle of the box. Both are
 * reset panel-wide in resources/css/manage.css, so setting either here would only put
 * the fight back at the call site.
 */
const control =
  'h-8 w-full rounded border border-hairline bg-mg-surface-2 px-2 text-[13px] text-fg-1 outline-none transition-colors focus:border-state-live/50 disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
  <label class="grid grid-cols-1 items-baseline gap-1 py-1.5 sm:grid-cols-[13rem_minmax(0,1fr)] sm:gap-4">
    <span class="flex items-center gap-1 pt-1.5 text-[12px] font-medium text-fg-2 sm:justify-end sm:text-right">
      {{ label }}
      <span v-if="required" class="text-state-danger" aria-hidden="true">*</span>
    </span>

    <div class="min-w-0" :class="narrow ? 'sm:max-w-56' : ''">
      <slot>
        <span v-if="readonly" class="flex h-8 items-center text-[13px] text-fg-1" :class="mono ? 'font-mono' : ''">
          {{ modelValue ?? '—' }}
        </span>

        <select
          v-else-if="type === 'select'"
          :value="modelValue"
          :class="control"
          :disabled="disabled"
          @change="$emit('update:modelValue', $event.target.value)"
        >
          <option v-for="option in options" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>

        <!--
          No colour class. @tailwindcss/forms puts `appearance: none` on every checkbox in
          the app, which makes `accent-color` inert, so the `accent-state-live` that used to
          sit here was doing nothing and hid where the colour actually comes from: the box,
          its tick, its hover and its focus ring are all drawn by the native-control rules
          in resources/css/manage.css, panel-wide.
        -->
        <span v-else-if="type === 'checkbox'" class="flex h-8 items-center">
          <input
            type="checkbox"
            class="size-4 cursor-pointer"
            :checked="Boolean(modelValue)"
            :disabled="disabled"
            @change="$emit('update:modelValue', $event.target.checked)"
          />
        </span>

        <!--
          The switch Filament's Toggle renders. Still a real checkbox underneath, so it
          keeps the label association, the keyboard behaviour and the form semantics a
          styled div would throw away; only the box is swapped for the track and knob.

          The input keeps `opacity-0` rather than `sr-only` because opacity also suppresses
          the panel-wide focus outline the native-control rules draw on every checkbox.
          Without that this would grow a second ring, a teal dot at the left edge of the
          track, on top of the peer-focus-visible ring the track already shows.
        -->
        <span v-else-if="type === 'toggle'" class="flex h-8 items-center">
          <span class="relative inline-flex">
            <input
              type="checkbox"
              role="switch"
              class="peer size-0 opacity-0"
              :checked="Boolean(modelValue)"
              :disabled="disabled"
              @change="$emit('update:modelValue', $event.target.checked)"
            />
            <span
              aria-hidden="true"
              class="h-5 w-9 cursor-pointer rounded-full bg-fg-3/25 transition-colors peer-checked:bg-state-live peer-disabled:cursor-not-allowed peer-disabled:opacity-50 peer-focus-visible:ring-2 peer-focus-visible:ring-state-live/50"
            />
            <span
              aria-hidden="true"
              class="pointer-events-none absolute left-0.5 top-0.5 size-4 rounded-full bg-mg-surface-0 shadow transition-transform peer-checked:translate-x-4"
            />
          </span>
        </span>

        <textarea
          v-else-if="type === 'textarea'"
          :value="modelValue"
          rows="3"
          class="w-full rounded border border-hairline bg-mg-surface-2 px-2 py-1.5 text-[13px] text-fg-1 outline-none focus:border-state-live/50"
          :disabled="disabled"
          :placeholder="placeholder"
          @input="$emit('update:modelValue', $event.target.value)"
        />

        <input
          v-else
          :type="type"
          :value="modelValue"
          :class="[control, mono ? 'font-mono' : '']"
          :disabled="disabled"
          :placeholder="placeholder"
          :min="min"
          :max="max"
          :step="step"
          @input="$emit('update:modelValue', type === 'number' ? ($event.target.value === '' ? null : Number($event.target.value)) : $event.target.value)"
        />
      </slot>

      <p v-if="error" class="mt-1 text-[11px] text-state-danger">{{ error }}</p>
      <p v-else-if="helper" class="mt-1 text-[11px] text-fg-3">{{ helper }}</p>
    </div>
  </label>
</template>
