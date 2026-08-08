<script setup>
/**
 * Settings > Badges.
 *
 * Empty on purpose, and this is the pane where that matters most. The two fields anyone
 * would reach for here, the badge renderer class and the order window, are columns on the
 * event that the Events form already writes. Repeating either one on this screen would give
 * the convention two badge_class editors that cannot see each other, and the last save
 * would win without saying so.
 */
import { Head } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import SettingsEmpty from '@/Components/Manage/SettingsEmpty.vue';

defineProps({
  event: { type: Object, default: null },
  canEdit: { type: Boolean, default: false },
  links: { type: Array, default: () => [] },
});
</script>

<template>
  <Head title="Badge settings" />

  <SettingsLayout>
    <SettingsEmpty
      title="Nothing badge-specific to configure yet"
      body="The badge renderer class is a field on the event and is edited in Events, together with the order window and the free badge deadline that decide when and at what price a badge can be ordered. Badge records themselves live in the Badges module. Nothing is mirrored here, so there is one place to change each of them."
      :links="links"
    />
  </SettingsLayout>
</template>
