<script setup>
/**
 * The honest "there is nothing to configure here yet" pane body.
 *
 * Three of the four Settings panes have no field of their own, because every configurable
 * column on the event is already owned by the Events form and inventing one here would
 * make it editable in two places. Rather than filling the pane with settings nothing reads,
 * it says so and names the screen that does own the adjacent fields.
 *
 * The links are read-only pointers, built server side and already filtered by route
 * existence and policy, so a reviewer is never pointed at a 403.
 */
import { Link } from '@inertiajs/vue3';
import FormSection from './FormSection.vue';
import ManageIcon from './ManageIcon.vue';

defineProps({
  title: { type: String, required: true },
  body: { type: String, required: true },
  /** [{ label, url }], possibly empty. */
  links: { type: Array, default: () => [] },
});
</script>

<template>
  <FormSection :title="title">
    <div class="space-y-3 py-2">
      <p class="max-w-prose text-[13px] leading-[18px] text-fg-2">{{ body }}</p>

      <div v-if="links.length" class="space-y-0.5">
        <p class="pb-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-fg-3">
          Where this is edited
        </p>

        <Link
          v-for="link in links"
          :key="link.url"
          :href="link.url"
          class="flex h-7 items-center gap-1.5 text-[13px] text-state-live transition-colors hover:underline"
        >
          <ManageIcon name="arrow-right" :size="14" class="shrink-0" />
          <span class="truncate">{{ link.label }}</span>
        </Link>
      </div>
    </div>
  </FormSection>
</template>
