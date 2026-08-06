<script setup>
/**
 * Placeholder dashboard. It exists so phase 0 has a page that proves the shell renders:
 * strip, event selector, sidebar, content column. The four stat cards, the doughnut and
 * the bar chart land in phase 9.
 */
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const page = usePage();

const scope = computed(() => {
  const event = page.props.manageEvent;

  return event?.id ? `${event.name} (${event.year})` : 'All events';
});
</script>

<template>
  <Head title="Dashboard" />

  <ManageLayout>
    <PageHeader title="Dashboard" :subtitle="scope" />

    <div class="p-4 text-[13px] text-fg-2">
      <p>Scoped to <span class="font-medium text-fg-1">{{ scope }}</span>.</p>
      <p class="mt-1 text-fg-3">Stats and charts arrive with phase 9.</p>
    </div>
  </ManageLayout>
</template>
