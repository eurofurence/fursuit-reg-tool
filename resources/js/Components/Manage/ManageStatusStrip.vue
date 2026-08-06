<script setup>
/**
 * The strip an operator reads without navigating: which event everything is scoped to,
 * whether that event's order window is open, and the two counts staff act on, pending
 * fursuit approvals and printed-but-unverified cards.
 *
 * Every number is server-decided. The segments arrive shaped, tone and all, from
 * App\Support\Manage\Navigation::strip(), and a segment whose module has not been built
 * yet arrives with a null url and renders as plain text. Nothing here fabricates a
 * number and nothing here picks a colour.
 *
 * Polls on its own interval and reloads only its own prop, so it keeps ticking while a
 * list page is being filtered or a form is being filled in (plan 2.4, 15s).
 */
import { computed } from 'vue';
import { Link, usePoll } from '@inertiajs/vue3';
import EventSelector from './EventSelector.vue';
import ManageIcon from './ManageIcon.vue';
import { resolve, toneDot, toneText } from './tones.js';

const props = defineProps({
  /** { id, name, year, orders_open, options: [...] } from App\Support\Manage\EventScope. */
  event: { type: Object, default: null },
  /** { segments: [{ key, label, value, tone, icon, url }] } from Navigation::strip(). */
  strip: { type: Object, default: null },
  user: { type: Object, default: null },
});

usePoll(15000, { only: ['manageStrip'] });

const segments = computed(() => props.strip?.segments ?? []);

const ordersTone = computed(() => (props.event?.orders_open ? 'ok' : 'idle'));

const segment = 'flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide';
</script>

<template>
  <header
    class="sticky top-0 z-20 flex h-10 shrink-0 items-center gap-4 border-b border-hairline bg-mg-surface-1 px-3"
    aria-label="Manage status"
  >
    <EventSelector :event="event" />

    <span v-if="event?.id" :class="[segment, resolve(toneText, ordersTone)]">
      <span class="size-1.5 rounded-full" :class="resolve(toneDot, ordersTone)" />
      {{ event.orders_open ? 'Orders open' : 'Orders closed' }}
    </span>

    <component
      :is="item.url ? Link : 'span'"
      v-for="item in segments"
      :key="item.key"
      :href="item.url"
      :class="[segment, resolve(toneText, item.tone), item.url ? 'transition-opacity hover:opacity-80' : '']"
    >
      <ManageIcon v-if="item.icon" :name="item.icon" :size="13" />
      <span class="tabular-nums">{{ item.value }}</span> {{ item.label }}
    </component>

    <div class="ml-auto flex items-center gap-3">
      <span v-if="user" class="text-[11px] text-fg-3">{{ user.name }}</span>
      <a
        :href="route('welcome')"
        class="text-fg-3 transition-colors hover:text-fg-1"
        title="Back to the public site"
      >
        <ManageIcon name="external-link" :size="14" />
      </a>
    </div>
  </header>
</template>
