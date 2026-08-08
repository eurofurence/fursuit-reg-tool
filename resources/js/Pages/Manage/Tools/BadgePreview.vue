<script setup>
/**
 * Badge Preview, the successor to App\Filament\Pages\BadgePreview (audit 5.2).
 *
 * Look a badge up by custom id, read the six details back, then open or download its
 * PDF. No table, no writes.
 *
 * The loaded badge is URL state, not component state: the lookup POSTs and the server
 * redirects to `?custom_id=…`, so the details panel survives a reload and the two PDF
 * buttons are real anchors. `target="_blank"` on a Livewire redirect never opened a tab
 * (plan 2.10 #34, audit 49); ActionButton renders a GET action declared `newTab()` as an
 * `<a target="_blank">`, which does. Only `View PDF in Browser` declares it, matching the
 * blade, which put the attribute on that button alone.
 *
 * Every detail row falls back to an em-dash, because a fursuit whose species, owner or
 * event is soft-deleted is exactly the record that took the old page down (audit 113).
 * The server already resolved every value null-safely; this is the display half.
 */
import { useForm, Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  /** The id the query asked for, so a failed lookup still shows what was typed. */
  customId: { type: String, default: null },
  /** Null until a lookup finds one. */
  badge: { type: Object, default: null },
  /** The two server-declared PDF actions, empty when no badge is loaded. */
  actions: { type: Array, default: () => [] },
});

/** The blade's six rows, in its order, with its labels verbatim. */
const rows = [
  { key: 'custom_id', label: 'Custom ID:' },
  { key: 'fursuit_name', label: 'Fursuit Name:' },
  { key: 'species', label: 'Species:' },
  { key: 'owner', label: 'Owner:' },
  { key: 'event', label: 'Event:' },
  { key: 'badge_class', label: 'Badge Type:' },
];

const form = useForm({ custom_id: props.customId ?? '' });

const submit = () => form.post(route('manage.tools.badge-preview.lookup'), { preserveScroll: true });
</script>

<template>
  <Head title="Badge Preview" />

  <ManageLayout>
    <PageHeader title="Badge Preview" subtitle="Look a badge up by custom ID and open its PDF" />

    <div class="flex-1 space-y-3 p-4">
      <form @submit.prevent="submit">
        <FormSection title="Load Badge">
          <!--
            The control is passed in rather than left to FormField's own input, because
            this one carries the TextInput's `maxLength(255)` and `maxlength` is not a
            prop the shared field exposes. Everything else is the shared field.
          -->
          <FormField
            label="Badge Custom ID"
            :error="form.errors.custom_id"
            required
            narrow
          >
            <input
              v-model="form.custom_id"
              type="text"
              maxlength="255"
              placeholder="Enter badge custom ID (e.g., ABC123)"
              class="h-8 w-full rounded border border-hairline bg-mg-surface-2 px-2 text-[13px] text-fg-1 outline-none transition-colors focus:border-state-live/50"
            />
          </FormField>

          <div class="flex justify-end pt-1.5">
            <button
              type="submit"
              class="h-8 rounded bg-state-live px-3 text-[13px] font-medium text-mg-surface-0 transition-opacity disabled:opacity-50"
              :disabled="form.processing"
            >
              <!-- The blade's label, unchanged while submitting: Filament's button kept
                   its own label too and only greyed itself out. -->
              Load Badge
            </button>
          </div>
        </FormSection>
      </form>

      <FormSection v-if="badge" title="Badge Details">
        <FormField
          v-for="row in rows"
          :key="row.key"
          :label="row.label"
          :model-value="badge[row.key] ?? '—'"
          readonly
        />

        <div v-if="actions.length" class="flex items-center gap-2 pt-2">
          <ActionButton v-for="action in actions" :key="action.name" :action="action" />
        </div>
      </FormSection>
    </div>
  </ManageLayout>
</template>
