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
 * destinations a daily operator already knows by name; they are wrong for four panes whose
 * names ("General", "Badges") say almost nothing about what is inside. So each entry is a
 * card: icon, title, and one line of what to expect. The cost is height, and four entries
 * is exactly the count that can afford it - if Settings ever grows past six or seven panes
 * this goes back to rows, because a menu that needs its own scrollbar is worse than a terse
 * one.
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

/*
 * The four panes, in the order the owner named them. Declared here rather than shipped as a
 * prop because all four routes live in one file and none of them is conditional: there is
 * no permission that hides one pane and not the others, so a server-built menu would be
 * four constants taking a round trip.
 *
 * `blurb` is what the card is for. Keep it to one line at this width - it is a signpost,
 * not documentation, and the pane itself explains the fields.
 */
const items = computed(() => [
  {
    key: 'general',
    label: 'General',
    icon: 'sliders-horizontal',
    blurb: 'The event this panel is configuring, and where its dates are edited.',
    url: route('manage.settings.general'),
  },
  {
    key: 'on-site-desk',
    label: 'On-Site Desk',
    icon: 'map-pin',
    blurb: 'Opening hours and the booth ranges attendees queue by.',
    url: route('manage.settings.on-site-desk'),
  },
  {
    key: 'printing',
    label: 'Printing',
    icon: 'printer',
    blurb: 'Printers, jobs and batches for the badge print run.',
    url: route('manage.settings.printing'),
  },
  {
    key: 'badges',
    label: 'Badges',
    icon: 'id-card',
    blurb: 'Badge design, pricing and what attendees may order.',
    url: route('manage.settings.badges'),
  },
]);

const path = (url) => new URL(url, window.location.origin).pathname.replace(/\/+$/, '') || '/';

const matches = (current, target) => current === target || current.startsWith(`${target}/`);

const active = computed(() => {
  const current = path(page.url.split('?')[0]);

  return items.value
    .filter((item) => matches(current, path(item.url)))
    .sort((a, b) => path(b.url).length - path(a.url).length)[0] ?? items.value[0];
});

const isActive = (item) => item.key === active.value.key;
</script>

<template>
  <ManageLayout>
    <!--
      One title for all four panes, so the header does not jump as the submenu is walked;
      the pane name rides in the subtitle, where the submenu highlight also says it.
    -->
    <PageHeader title="Settings" :subtitle="active.label">
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
      <div class="min-w-0 flex-1 space-y-3 p-4">
        <slot />
      </div>
    </div>
  </ManageLayout>
</template>
