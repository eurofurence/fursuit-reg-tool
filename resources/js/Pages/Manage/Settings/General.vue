<script setup>
/**
 * Settings > General, the pane /admin/settings itself renders.
 *
 * Empty on purpose. Settings is per-event configuration, and every general field an event
 * has is already a column the Events form owns; adding a copy here would give the two
 * screens the same field and let one silently overwrite the other. So this pane states that
 * plainly and points at the form that owns them.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import SettingsEmpty from '@/Components/Manage/SettingsEmpty.vue';

const props = defineProps({
  /** { id, name } or null when the header selector is on "all events". */
  event: { type: Object, default: null },
  canEdit: { type: Boolean, default: false },
  links: { type: Array, default: () => [] },
});

const scope = computed(() => (props.event
  ? `Configuring ${props.event.name}, the event selected in the header. Every convention is configured separately.`
  : 'No event is selected in the header. Settings is per-event configuration, so pick one to see what it holds.'));
</script>

<template>
  <Head title="General settings" />

  <SettingsLayout>
    <p class="text-[13px] leading-[18px] text-fg-3">{{ scope }}</p>

    <SettingsEmpty
      title="Nothing general to configure yet"
      body="Settings holds per-event configuration. An event's name, its dates, its order window, its free badge deadline and its badge renderer class are fields on the event record and are edited in Events. They are deliberately not repeated here: a field editable on two screens is a field where one screen quietly wins."
      :links="links"
    />
  </SettingsLayout>
</template>
