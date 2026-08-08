<script setup>
/**
 * The Settings shell: a second vertical menu inside the page body, with the active pane to
 * the right of it.
 *
 * This is nested navigation, not a replacement for anything. ManageLayout still owns the
 * 40px status strip and the 240px rail; this only splits what is left of the content column
 * once, below the page header. The rail highlights "Settings" for every pane because every
 * pane lives under /admin/settings, and the submenu says which one.
 *
 * The menu is a list of links, not tab state, because each pane is a real URL (see
 * routes/manage/settings.php). Highlighting therefore reads the path, using the same
 * longest-match rule as ManageSidebar: "/admin/settings" is a prefix of every other pane,
 * so a plain prefix match would light General on all four screens.
 *
 * CARDS, NOT ROWS, AND ONLY HERE. ManageSidebar's h-9 rows are right for a rail of twenty
 * destinations a daily operator already knows by name; they are wrong for panes whose names
 * ("General", "Badges") say almost nothing about what is inside. So each entry is a card:
 * icon, title, and one line of what to expect. The cost is height, and the current handful
 * of panes can afford it - if Settings ever grows past six or seven this goes back to rows,
 * because a menu that needs its own scrollbar is worse than a terse one.
 *
 * The colours stay ManageSidebar's: `bg-state-live/10` plus the 2px bar down the left edge
 * marks the active card, so nothing here invents a second visual language for "selected".
 * The one hairline between menu and content comes from the border on the <nav>, which
 * spans the full row because the flex row stretches.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const page = usePage();

defineProps({
  /**
   * Overrides the header subtitle, which is otherwise the active pane's name. Only the
   * Events form passes one: it is the single settings screen that is about one record
   * rather than about a pane, and "Settings / Events" on both create and edit would say
   * nothing about which.
   */
  subtitle: { type: String, default: null },
  /**
   * Drops the content column's own padding and spacing, for a pane that is a full module
   * screen rather than a stack of setting cards: the Events table wants to reach the edges
   * like every other list in the panel, and the Events form already pads itself.
   */
  flush: { type: Boolean, default: false },
});

/*
 * The panes come from the server (Navigation::settings(), shared as `manageSettingsNav`),
 * because Events is gated by EventPolicy and a client-side list would offer a reviewer a
 * card that 403s. Everything the card renders - label, icon, blurb, url - is decided there.
 */
const items = computed(() => page.props.manageSettingsNav ?? []);

const path = (url) => new URL(url, window.location.origin).pathname.replace(/\/+$/, '') || '/';

const matches = (current, target) => current === target || current.startsWith(`${target}/`);

const active = computed(() => {
  const current = path(page.url.split('?')[0]);

  return items.value
    .filter((item) => matches(current, path(item.url)))
    .sort((a, b) => path(b.url).length - path(a.url).length)[0] ?? items.value[0] ?? null;
});

const isActive = (item) => item.key === active.value?.key;
</script>

<template>
  <ManageLayout>
    <!--
      One title for every pane, so the header does not jump as the submenu is walked; the
      pane name rides in the subtitle, where the submenu highlight also says it.
    -->
    <PageHeader title="Settings" :subtitle="subtitle ?? active?.label">
      <template #actions>
        <slot name="actions" />
      </template>
    </PageHeader>

    <div class="flex min-h-0 flex-1 items-stretch">
      <nav class="w-64 shrink-0 border-r border-hairline" aria-label="Settings sections">
        <!--
          sticky rather than its own scroller: ManageLayout's <main> is the scroll container
          for the page, so a tall pane keeps the menu in view instead of scrolling it off.
        -->
        <div class="sticky top-0 space-y-1 p-2">
          <Link
            v-for="item in items"
            :key="item.key"
            :href="item.url"
            class="relative flex gap-2.5 overflow-hidden rounded px-3 py-2.5 transition-colors"
            :class="isActive(item)
              ? 'bg-state-live/10 text-state-live'
              : 'text-fg-2 hover:bg-mg-surface-2'"
            :aria-current="isActive(item) ? 'page' : null"
          >
            <span
              v-if="isActive(item)"
              class="absolute top-1 bottom-1 left-0 w-0.5 rounded-r bg-state-live"
              aria-hidden="true"
            />

            <ManageIcon :name="item.icon" :size="16" class="mt-px shrink-0" />

            <span class="min-w-0 flex-1">
              <span
                class="block truncate text-[13px] font-medium"
                :class="isActive(item) ? 'text-state-live' : 'text-fg-1'"
              >
                {{ item.label }}
              </span>
              <!--
                Not truncated: a blurb cut mid-sentence tells the operator less than no
                blurb, so it wraps and the card grows instead.
              -->
              <span class="mt-0.5 block text-[11px] leading-[15px] text-fg-3">
                {{ item.blurb }}
              </span>
            </span>
          </Link>
        </div>
      </nav>

      <!-- min-w-0 for the same reason ManageLayout gives its content column: without it a
           wide pane widens the row instead of clipping or scrolling inside it. -->
      <div
        class="flex min-w-0 flex-1 flex-col"
        :class="flush ? '' : 'space-y-3 p-4'"
      >
        <slot />
      </div>
    </div>
  </ManageLayout>
</template>
