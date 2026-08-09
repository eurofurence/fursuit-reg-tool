<script setup>
/**
 * Shell for the /manage panel: a full-height sidebar down the left edge, and a content
 * column to the right of it that owns its own header.
 *
 * The strip used to span the whole page above the sidebar, which is why nothing in it
 * lined up: its first segment sat at x=0 while every page under it started at the rail's
 * edge. Handing the full height to the rail and the header to the content column makes
 * the header's left edge and the page's left edge the same edge, and gives the brand a
 * place to live above the menu instead of nowhere.
 *
 * The `manage` class claims the canvas only. The surface, foreground and state tokens
 * live on :root in manage.css, not under this class, because the panel's overlays are
 * teleported to <body>, outside this subtree; a ManageDialog scoped under `.manage`
 * would render against undefined custom properties. Same lesson pos.css already records.
 *
 * The stylesheet is imported here rather than from app.js on purpose: app.js is being
 * reworked for the POS on this branch, and /manage is the only consumer of these tokens.
 */
import '../../css/manage.css';

import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import ManageSidebar from '@/Components/Manage/ManageSidebar.vue';
import ManageStatusStrip from '@/Components/Manage/ManageStatusStrip.vue';
import ToastHost from '@/Components/Manage/ToastHost.vue';

const page = usePage();

/**
 * The nav drawer, below md only. Owned here rather than in the sidebar because the scrim
 * belongs to the shell, and because the button that opens it lives in the header on the
 * other side of the seam.
 */
const navOpen = ref(false);

// Any navigation closes it. The sidebar closes itself on its own links, but a row action,
// a redirect after a save or the browser's back button all move the page without going
// near it, and a drawer left open over the new page is the worst of both layouts.
watch(() => page.url, () => {
  navOpen.value = false;
});
</script>

<template>
  <div
    class="manage flex h-screen overflow-hidden bg-mg-surface-0 text-fg-1 antialiased"
    @keydown.esc="navOpen = false"
  >
    <ManageSidebar
      :groups="page.props.manageNav ?? []"
      :open="navOpen"
      @close="navOpen = false"
    />

    <!--
      The scrim, drawer-only. It is what makes a tap anywhere on the page dismiss the menu,
      which on a phone is the gesture people actually reach for before hunting for a close
      button. md:hidden rather than unmounting at the breakpoint so there is nothing to
      re-mount when a tablet is rotated.
    -->
    <div
      v-if="navOpen"
      class="fixed inset-0 z-30 bg-black/50 md:hidden"
      aria-hidden="true"
      @click="navOpen = false"
    />

    <!--
      The content column. Fluid, no max-width: matches the panel's maxContentWidth('100%').

      min-w-0 is load-bearing, not tidiness: a flex item refuses to shrink below its
      content by default, so without it the header's left group would push this column
      wider than the viewport and hand the whole page a horizontal scrollbar instead of
      clipping the least important segment, which is the one thing that group is built to
      do.
    -->
    <div class="flex min-w-0 flex-1 flex-col">
      <ManageStatusStrip
        :event="page.props.manageEvent"
        :strip="page.props.manageStrip"
        :user="page.props.auth?.user"
      >
        <template #leading>
          <!--
            -ml-1 pulls the icon's own padding back so the button's glyph sits on the
            header's px-4 rather than 4px inside it, which is the line the page title
            below it starts on.
          -->
          <button
            type="button"
            class="-ml-1 flex size-8 shrink-0 items-center justify-center rounded text-fg-2 outline-none transition-colors hover:bg-mg-surface-2 hover:text-fg-1 focus-visible:ring-1 focus-visible:ring-state-live/60 md:hidden"
            :aria-expanded="navOpen"
            aria-controls="manage-nav"
            aria-label="Open navigation"
            @click="navOpen = true"
          >
            <ManageIcon name="menu" :size="18" />
          </button>
        </template>
      </ManageStatusStrip>

      <!--
        The header is a flex row above this, so what scrolls is the page content and only
        the page content: the header stays put and the rail stays put, both without the
        viewport ever scrolling. min-h-0 is the same story as min-w-0 above, in the other
        axis - it is what lets this shrink to the space left over and scroll, rather than
        growing the column past the bottom of the screen.
      -->
      <main class="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto">
        <slot />
      </main>
    </div>

    <ToastHost />
  </div>
</template>
