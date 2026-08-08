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

import { usePage } from '@inertiajs/vue3';
import ManageSidebar from '@/Components/Manage/ManageSidebar.vue';
import ManageStatusStrip from '@/Components/Manage/ManageStatusStrip.vue';
import ToastHost from '@/Components/Manage/ToastHost.vue';

const page = usePage();
</script>

<template>
  <div class="manage flex h-screen overflow-hidden bg-mg-surface-0 text-fg-1 antialiased">
    <ManageSidebar :groups="page.props.manageNav ?? []" />

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
      />

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
