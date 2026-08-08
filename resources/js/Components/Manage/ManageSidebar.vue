<script setup>
/**
 * Permanent left sidebar, full viewport height down the left edge, always labelled,
 * no collapse.
 *
 * An icon rail saves 180px and costs a guess on every click; this panel is used to edit
 * things, not to watch a wall, so the labels stay. Fixed width also means the content
 * column never reflows mid-session.
 *
 * The rail owns the whole left edge rather than starting below the strip, and it carries
 * the brand at its top. The strip used to span the page above the menu, which put its
 * left-most segment at x=0 while every page below it started at the rail's edge, so
 * nothing in the header lined up with anything under it. Giving the rail the full height
 * moves the header into the content column, where its left edge and the page's left edge
 * are the same edge.
 *
 * Width and brand height come from --mg-rail and --mg-strip rather than from numbers
 * typed here: the brand block has to be exactly as tall as the header on the other side
 * of the seam or the two hairlines under them do not meet. This component used to hardcode
 * 220px against a 240px token, which is the drift that removes.
 *
 * Groups and badges come from App\Support\Manage\Navigation, which declares the group
 * order the Filament panel never had and drops items whose route does not exist yet -
 * that is how rebuild phases add modules without touching this component.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ManageIcon from './ManageIcon.vue';
import { resolve, toneBadge } from './tones.js';

const props = defineProps({
  groups: { type: Array, default: () => [] },
});

const page = usePage();

const path = (url) => new URL(url, window.location.origin).pathname.replace(/\/+$/, '') || '/';

const matches = (current, target) => current === target
  || current.startsWith(target === '/' ? '/' : `${target}/`);

/**
 * Only the deepest matching item lights up. A plain prefix match lit every ancestor
 * too, so /admin/print-batches also highlighted Dashboard (/admin). The longest match
 * still keeps a detail page on its section, e.g. /admin/fursuits/3 highlights Fursuits.
 */
const activeRoute = computed(() => {
  const current = path(page.url.split('?')[0]);

  return props.groups
    .flatMap((group) => group.items ?? [])
    .map((item) => ({ route: item.route, target: path(item.url) }))
    .filter((item) => matches(current, item.target))
    .sort((a, b) => b.target.length - a.target.length)[0]?.route ?? null;
});

const isActive = (item) => item.route === activeRoute.value;
</script>

<template>
  <!--
    A plain div, not an <aside>: the only landmark in here is the <nav> below, and it is
    the one screen readers already know by name. Wrapping it in a complementary landmark
    would add a second, unnamed one for a brand link.
  -->
  <div class="flex h-full w-mg-rail shrink-0 flex-col border-r border-hairline bg-mg-surface-1">
    <!--
      h-mg-strip, the same token the header uses, so the hairline under the brand and the
      hairline under the header are one continuous line across the seam.

      The string is hardcoded because there is nothing to read it from: config('app.name')
      is still Laravel, and the only place the product is named is the <title> in
      app.blade.php, which never reaches the client. The public Layout.vue spells the brand
      out in markup too.
    -->
    <Link
      :href="route('admin.dashboard')"
      class="flex h-mg-strip shrink-0 items-center border-b border-hairline px-4 text-[13px] font-semibold tracking-wide text-fg-1 outline-none transition-colors hover:text-state-live focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-state-live/40"
    >
      Fursuit Badges
    </Link>

    <!--
      min-h-0 is what lets flex-1 shrink below the nav's content height, which is what
      makes the rail itself the thing that scrolls when the module list outgrows the
      viewport. Without it a long list pushes the rail past the bottom of the screen and
      the last groups become unreachable. overscroll-contain keeps that scroll from
      chaining into the page behind it.
    -->
    <nav
      class="min-h-0 flex-1 overflow-y-auto overscroll-contain"
      aria-label="Manage navigation"
    >
      <div v-for="group in groups" :key="group.label" class="py-2">
        <p class="px-4 pb-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-fg-3">
          {{ group.label }}
        </p>

        <Link
          v-for="item in group.items ?? []"
          :key="item.route"
          :href="item.url"
          class="relative flex h-9 items-center gap-2.5 px-4 text-[13px] transition-colors"
          :class="isActive(item)
            ? 'bg-state-live/10 font-medium text-state-live'
            : 'text-fg-2 hover:bg-mg-surface-2 hover:text-fg-1'"
        >
          <span
            v-if="isActive(item)"
            class="absolute top-1 bottom-1 left-0 w-0.5 rounded-r bg-state-live"
            aria-hidden="true"
          />
          <ManageIcon :name="item.icon" :size="16" class="shrink-0" />
          <span class="flex-1 truncate">{{ item.label }}</span>
          <span
            v-if="item.badge"
            class="rounded px-1 text-[10px] font-medium ring-1 ring-inset"
            :class="resolve(toneBadge, item.badge.tone)"
          >{{ item.badge.label }}</span>
        </Link>
      </div>
    </nav>
  </div>
</template>
