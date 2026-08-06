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
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
  /** { id, name, year, orders_open, options: [{ id, name, year, orders_open }] } */
  event: { type: Object, default: null },
});

const processing = ref(false);

const options = computed(() => props.event?.options ?? []);

/**
 * "" is the wire form of null. A select cannot hold null, and `all` was the old string
 * that the middleware special-cased into a bug.
 */
const current = computed(() => (props.event?.id == null ? '' : String(props.event.id)));

const optionLabel = (option) => {
  const marker = option.orders_open ? '✓ Orders Open' : '✗ Orders Closed';

  return `${option.name} (${option.year})  ${marker}`;
};

const choose = (value) => {
  if (value === current.value) {
    return;
  }

  processing.value = true;

  router.post(
    route('manage.event.select'),
    { event_id: value === '' ? null : Number(value) },
    {
      preserveScroll: true,
      onFinish: () => {
        processing.value = false;
      },
    },
  );
};
</script>

<template>
  <label class="flex items-center gap-1.5">
    <span class="sr-only">Event</span>

    <select
      class="h-7 max-w-64 rounded border border-hairline bg-mg-surface-2 px-1.5 text-[12px] font-medium text-fg-1 outline-none transition-colors focus:border-state-live/50 disabled:opacity-50"
      :value="current"
      :disabled="processing"
      @change="choose($event.target.value)"
    >
      <option value="">All events</option>
      <option v-for="option in options" :key="option.id" :value="String(option.id)">
        {{ optionLabel(option) }}
      </option>
    </select>
  </label>
</template>
