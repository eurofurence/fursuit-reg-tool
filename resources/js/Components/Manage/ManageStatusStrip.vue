<script setup>
/**
 * The strip an operator reads without navigating: which event everything is scoped to,
 * whether that event's order window is open, and the counts staff act on: fursuits waiting
 * on a review, badges whose card has not been printed yet, and badges already printed.
 *
 * Every number is server-decided. The segments arrive shaped, tone and all, from
 * App\Support\Manage\Navigation::strip(), and a segment whose module has not been built
 * yet arrives with a null url and renders as plain text. Nothing here fabricates a
 * number and nothing here picks a colour.
 *
 * Belongs to the content column, not to the page. It starts at the sidebar's edge, which
 * is what puts "Orders open" directly above the first column of whatever list is below
 * it; spanning the full page put the strip's first segment at x=0 and the page's first
 * column 240px to the right of it, and the two never lined up.
 *
 * Layout is two groups, and the split is the point. The left group is the status
 * readout and nothing else, which is what a status strip is for; it is the group allowed
 * to shrink and clip, so a narrow window costs the least important text first rather
 * than giving the page a horizontal scrollbar. The right group is the controls, event
 * filter then who you are then the way out, and it never shrinks. The selector used to
 * lead the strip, jammed against the sidebar edge and wide enough to overflow.
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

const ordersLabel = computed(() => (props.event?.orders_open ? 'Orders open' : 'Orders closed'));

// shrink-0 per segment: the group clips whole segments at its edge rather than
// squeezing every one of them into an unreadable column.
const segment
  = 'flex shrink-0 items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide';
</script>

<template>
  <!--
    h-mg-strip, not h-10: the sidebar's brand block reads the same token, and the two have
    to agree exactly or the hairline under the brand and the hairline under this one meet
    at a step. They are the same 40px either way, but only one of them is now a number
    somebody could change alone.

    sticky is kept even though the layout already pins this - the content column puts the
    scroll on <main> underneath, so this never scrolls away on its own. It costs nothing
    and it is the behaviour any page that scrolls the header's own container still wants.

    px-4, matching PageHeader, is the last of the alignment: moving the strip into the
    content column got "Orders open" to within 4px of the page title under it, and the
    4px was this padding disagreeing with the one every page below uses.
  -->
  <header
    class="sticky top-0 z-20 flex h-mg-strip shrink-0 items-center gap-3 border-b border-hairline bg-mg-surface-1 px-4"
    aria-label="Manage status"
  >
    <!--
      min-w-0 is what makes flex-1 able to shrink below its content, and overflow-hidden
      is what keeps the excess out of the document instead of widening the page. It is
      scoped to this group, never to the header, because the event popover overflows the
      header on purpose.
    -->
    <div class="flex min-w-0 flex-1 items-center gap-4 overflow-hidden">
      <!--
        Below md the words go and the dot carries the state, so the title has to say it;
        the dot alone is not a label.
      -->
      <span
        v-if="event?.id"
        :class="[segment, resolve(toneText, ordersTone)]"
        :title="ordersLabel"
      >
        <span class="size-1.5 rounded-full" :class="resolve(toneDot, ordersTone)" />
        <span class="sr-only md:not-sr-only">{{ ordersLabel }}</span>
      </span>

      <component
        :is="item.url ? Link : 'span'"
        v-for="item in segments"
        :key="item.key"
        :href="item.url"
        :title="`${item.value} ${item.label}`"
        :class="[segment, resolve(toneText, item.tone), item.url ? 'transition-opacity hover:opacity-80' : '']"
      >
        <ManageIcon v-if="item.icon" :name="item.icon" :size="13" />
        <span class="tabular-nums">{{ item.value }}</span>
        <span class="sr-only md:not-sr-only">{{ item.label }}</span>
      </component>
    </div>

    <div class="flex shrink-0 items-center gap-3">
      <EventSelector :event="event" />

      <!-- Identity is the first thing worth dropping when there is no room for it. -->
      <span v-if="user" class="hidden max-w-[10rem] truncate text-[11px] text-fg-3 sm:inline">
        {{ user.name }}
      </span>

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
