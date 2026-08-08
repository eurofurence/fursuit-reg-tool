<script setup>
/**
 * The Tools index: one card per tool, replacing the Tools and Maintenance rail groups.
 *
 * Cards, not rows, for the reason SettingsLayout gives: "DB Service" and "PDF Generator"
 * are names that say almost nothing about what the page does, so each entry carries a line
 * of what to expect. There are three of them and there is no table here, so the grid can
 * afford the height a rail row cannot.
 *
 * The list is server-declared (Navigation::tools()). Nothing on this page decides what is
 * on it or who may see it: a tool whose route is not registered, and DB Service for anyone
 * without `manage-admin`, simply never arrive.
 *
 * `danger` marks the one card that writes. It is a border and an icon tint, not a colour
 * over the whole card: the repair is a normal destination, it just should not read like the
 * two read-only exports beside it.
 */
import { Head, Link } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

defineProps({
  /** `{ key, label, icon, blurb, url, danger }` each, already filtered by route and gate. */
  tools: { type: Array, default: () => [] },
});
</script>

<template>
  <Head title="Tools" />

  <ManageLayout>
    <PageHeader title="Tools" subtitle="Pages that run something over the data other modules own" />

    <div class="p-4">
      <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <Link
          v-for="tool in tools"
          :key="tool.key"
          :href="tool.url"
          class="flex gap-3 rounded border p-4 transition-colors"
          :class="tool.danger
            ? 'border-state-danger/40 bg-mg-surface-1 hover:bg-mg-surface-2'
            : 'border-hairline bg-mg-surface-1 hover:bg-mg-surface-2'"
        >
          <ManageIcon
            :name="tool.icon"
            :size="18"
            class="mt-px shrink-0"
            :class="tool.danger ? 'text-state-danger' : 'text-state-live'"
          />

          <span class="min-w-0 flex-1">
            <span class="block text-[13px] font-medium text-fg-1">{{ tool.label }}</span>

            <!-- Not truncated: a blurb cut mid-sentence tells the operator less than none. -->
            <span class="mt-1 block text-[12px] leading-[17px] text-fg-3">{{ tool.blurb }}</span>
          </span>
        </Link>
      </div>
    </div>
  </ManageLayout>
</template>
