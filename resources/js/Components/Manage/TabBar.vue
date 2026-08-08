<script setup>
/**
 * The preset views a table declares, drawn as a real tablist above the toolbar.
 *
 * Why this is not part of FilterBar. A filter is one narrowing among several that can all
 * be on at once; a tab is the view itself, exactly one of them, chosen before anything is
 * refined. Putting them on the same row would say they combine the same way, and an
 * operator would reasonably try to hold Admins and Reviewers at once. So the strip sits
 * above the toolbar band, in the page header area under the title, and the reading order
 * down the page is the order of the decisions: pick a view, then refine it. It is also why
 * the strip carries no surface of its own - it belongs to the header, not to the band of
 * controls below it.
 *
 * Where the active tab comes from. Not from a prop: `tabs` is not one of the five props
 * `useTableQuery` reloads on a partial visit, so a server-sent `active` flag would be
 * whatever the last full page load said and would freeze on the first tab the moment the
 * operator switched. The URL is the state, exactly as it is for filters, sort and page, so
 * that is what is read - through `usePage().url`, which Inertia updates on every visit
 * including the partial one a tab switch makes. The two fallback rules are mirrored from
 * App\Support\Manage\Table: a missing key means the first declared tab, and an unknown key
 * means the first declared tab. If they ever drift, the strip would highlight one view
 * while the rows below it showed another.
 *
 * The ARIA pattern, and why it is this one. These are links: every view has a URL, and
 * taking that away to gain a role would cost middle-click, copy-link and open-in-new-tab
 * on the panel's most shareable state. So they stay anchors with a real href, and the tab
 * roles are added with all the behaviour those roles promise actually implemented, rather
 * than sprinkled on: a roving tabindex so the strip is one stop in the tab order, arrow
 * keys and Home/End to move between the tabs, aria-selected on the chosen one, and
 * aria-controls pointing at the panel that holds the toolbar, the rows and the pager. The
 * trade is that role="tab" overrides the link role, so these no longer appear in a screen
 * reader's list of links; that is the accepted cost of the pattern and the reason the
 * href is still there for everyone else.
 *
 * Activation is manual, not automatic. APG only recommends activating on arrow when
 * showing the panel is instant; here it is a request to the server, so arrowing across
 * three tabs would fire three visits and stack three history entries. Arrow keys move
 * focus, Enter or Space commits. Enter is the anchor's own behaviour; Space is not, so it
 * is handled, because role="tab" promises it.
 */
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { activeTabKey, useTableQuery } from './useTableQuery.js';

const props = defineProps({
  /** Server-declared preset views: `{ key, label, count }`, first is the default. */
  tabs: { type: Array, required: true },
  /** The id of the element these tabs control, so aria-controls has something to name. */
  panelId: { type: String, required: true },
});

const page = usePage();

const { setTab, tabUrl } = useTableQuery();

const defaultKey = computed(() => props.tabs[0]?.key ?? null);

const activeKey = computed(() => activeTabKey(props.tabs, page.url));

// The default view is the bare URL, so its param is dropped rather than written out.
const paramFor = (tab) => (tab.key === defaultKey.value ? null : tab.key);

// Derived from the panel id rather than declared, because DataTable has to build the same
// string for the panel's aria-labelledby and neither side should be typing it twice.
const tabId = (tab) => `${props.panelId}-tab-${tab.key}`;

const elements = ref([]);

const select = (tab, event) => {
  // A modified or middle click is the operator asking the browser for a new tab or window.
  // The href is real, so the right thing to do is stay out of the way.
  if (event && (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0)) {
    return;
  }

  event?.preventDefault();

  if (tab.key === activeKey.value) {
    return;
  }

  setTab(paramFor(tab));
};

const move = (index, step) => {
  // Wraps, which is what a tablist does: at the last tab, Right goes back to the first.
  const next = (index + step + props.tabs.length) % props.tabs.length;

  elements.value[next]?.focus();
};

const onKeydown = (tab, index, event) => {
  switch (event.key) {
    case 'ArrowRight':
    case 'ArrowDown':
      event.preventDefault();
      move(index, 1);
      break;
    case 'ArrowLeft':
    case 'ArrowUp':
      event.preventDefault();
      move(index, -1);
      break;
    case 'Home':
      event.preventDefault();
      elements.value[0]?.focus();
      break;
    case 'End':
      event.preventDefault();
      elements.value[props.tabs.length - 1]?.focus();
      break;
    case ' ':
      // Enter already activates an anchor; Space does not, and role="tab" says it must.
      event.preventDefault();
      select(tab, null);
      break;
    default:
      break;
  }
};
</script>

<template>
  <!--
    h-8 and no surface: a header-area strip, not a second control band. px-4 rather than
    the toolbar's px-3, so the first tab starts on the same left edge as the page title
    above it - it is part of the header area, and reads as one only if it lines up with it.
    The container's hairline is the one the active tab's 2px underline sits on, which is
    why the tabs pull themselves down a pixel rather than the strip growing to fit an
    indicator.

    overflow-x-auto is why the tabs' focus ring is inset. A tab is exactly as tall as the
    strip, so an outset ring would be drawn outside the container's padding box - and
    overflow-x on a box forces overflow-y to auto, so it would be clipped away and the
    keyboard user would have no focus indicator at all. The sidebar's links are inset for
    the same reason.
  -->
  <div
    role="tablist"
    aria-label="Preset views"
    class="flex h-8 shrink-0 items-center gap-4 overflow-x-auto border-b border-hairline px-4"
  >
    <a
      v-for="(tab, index) in tabs"
      :key="tab.key"
      :ref="(el) => (elements[index] = el)"
      :id="tabId(tab)"
      role="tab"
      :href="tabUrl(paramFor(tab))"
      :aria-selected="tab.key === activeKey"
      :aria-controls="panelId"
      :tabindex="tab.key === activeKey ? 0 : -1"
      class="-mb-px inline-flex h-8 shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 text-[13px] leading-none outline-none transition-colors focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-state-live/40"
      :class="
        tab.key === activeKey
          ? 'border-state-live text-fg-1'
          : 'border-transparent text-fg-2 hover:text-fg-1'
      "
      @click="select(tab, $event)"
      @keydown="onKeydown(tab, index, $event)"
    >
      {{ tab.label }}

      <!--
        A count of the tab's own view, not of the current filters, so it does not move
        while the operator types in the search box. Tabs may overlap - a user can be both
        an admin and a reviewer - so these are not expected to sum to the All count.
      -->
      <span
        v-if="tab.count !== null && tab.count !== undefined"
        class="rounded bg-mg-surface-2 px-1 py-0.5 text-[11px] tabular-nums text-fg-3"
      >{{ tab.count }}</span>
    </a>
  </div>
</template>
