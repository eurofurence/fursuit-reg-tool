<script setup>
/**
 * Settings > Printing.
 *
 * Empty on purpose. Nothing about printing is configured per event today: printers, print
 * jobs and print batches are records with their own modules, and the queue and QZ Tray
 * wiring are deployment inputs in config/ and .env rather than something an operator tunes
 * per convention. Inventing a printing setting here would mean shipping a control no code
 * reads.
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
  <Head title="Printing settings" />

  <SettingsLayout>
    <SettingsEmpty
      title="Nothing to configure for printing yet"
      body="Printing has no per-event setting. Printers, print jobs and print batches are records, each with its own module, and the printer host, the queue and the QZ Tray wiring are deployment values in config and the environment, not something that should differ between two conventions in the same database."
      :links="links"
    />
  </SettingsLayout>
</template>
