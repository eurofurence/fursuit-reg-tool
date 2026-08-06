<script setup>
/**
 * Shell for the /manage panel: status strip across the top, permanent sidebar on the
 * left, page content to the right of it.
 *
 * The `manage` class claims the canvas only. The surface, foreground and state tokens
 * live on :root in manage.css, not under this class, because PrimeVue teleports its
 * overlays to <body>, outside this subtree; a confirm dialog scoped under `.manage`
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
  <div class="manage flex h-screen flex-col overflow-hidden bg-mg-surface-0 text-fg-1 antialiased">
    <ManageStatusStrip
      :event="page.props.manageEvent"
      :strip="page.props.manageStrip"
      :user="page.props.auth?.user"
    />

    <div class="flex min-h-0 flex-1">
      <ManageSidebar :groups="page.props.manageNav ?? []" />

      <!-- Fluid, no max-width: matches the panel's maxContentWidth('100%'). -->
      <main class="flex min-w-0 flex-1 flex-col overflow-y-auto">
        <slot />
      </main>
    </div>

    <ToastHost />
  </div>
</template>
