<script setup>
/**
 * Settings > General, the pane /admin/settings itself renders.
 *
 * A placeholder by design. Settings is per-event configuration, and every general field an
 * event has is already a column the Events form owns; adding a copy here would give the two
 * screens the same field and let one silently overwrite the other. So the landing pane
 * carries no fields at all and points at the submenu beside it.
 */
import { Head } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';

defineProps({
  /** { id, name } or null when the header selector is on "all events". */
  event: { type: Object, default: null },
  canEdit: { type: Boolean, default: false },
});
</script>

<template>
  <Head title="Settings" />

  <SettingsLayout>
    <div class="flex flex-1 items-center justify-center py-16">
      <p class="max-w-prose text-center text-[13px] leading-[18px] text-fg-3">
        Please select a config option from the left.
      </p>
    </div>
  </SettingsLayout>
</template>
