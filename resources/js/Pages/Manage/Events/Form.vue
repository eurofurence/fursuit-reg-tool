<script setup>
/**
 * Create and edit an event, as a page rather than the modal a ManageRecords page gave it
 * (plan 1.2). One component for both: the field list is identical and only the target
 * route and the button copy differ.
 *
 * The section headings started as EventResource's `Group::make([...])->label(...)` wrappers.
 * `Group` is an invisible layout component in Filament v3, so those labels rendered
 * nowhere (audit 108); they are shipped as real headings here. Two departures from that
 * schema: the "Financial Tracking" group is gone (the `cost` field it held was never read
 * by anything), and Catch-Em-All is its own card rather than a sub-part of the gallery,
 * because the game and the public gallery are separate features.
 *
 * Every date field carries a description of what it actually decides, because most of them
 * decide nothing and the two that matter most (`order_starts_at`, `free_badge_deadline`)
 * are not self-explanatory from their labels.
 *
 * `starts_at` and `ends_at` are date-only controls and the other five date fields are
 * date-and-time, which is the granularity each one has today (plan 2.6).
 *
 * There is no state field. Event state is computed from the dates by `Event::state()`, so
 * the order window fields below are the only lever over it.
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  /** null on create. */
  event: { type: Object, default: null },
  badgeClassOptions: { type: Array, required: true },
});

const editing = computed(() => Boolean(props.event?.id));

const form = useForm({
  name: props.event?.name ?? '',
  badge_class: props.event?.badge_class ?? '',
  starts_at: props.event?.starts_at ?? '',
  ends_at: props.event?.ends_at ?? '',
  order_starts_at: props.event?.order_starts_at ?? '',
  order_ends_at: props.event?.order_ends_at ?? '',
  free_badge_deadline: props.event?.free_badge_deadline ?? '',
  mass_printed_at: props.event?.mass_printed_at ?? '',
  // Toggle::make('catch_em_all_enabled')->default(true), which applies on create only.
  catch_em_all_enabled: props.event?.catch_em_all_enabled ?? true,
  catch_em_all_start: props.event?.catch_em_all_start ?? '',
  catch_em_all_end: props.event?.catch_em_all_end ?? '',
  archival_notice: props.event?.archival_notice ?? '',
});

// Filament renders an empty option until one is picked, and this Select was never required.
const badgeClasses = computed(() => [
  { value: '', label: 'Select an option' },
  ...props.badgeClassOptions,
]);

const submit = () => {
  if (editing.value) {
    form.put(route('manage.events.update', props.event.id));

    return;
  }

  form.post(route('manage.events.store'));
};
</script>

<template>
  <Head :title="editing ? 'Edit event' : 'New event'" />

  <ManageLayout>
    <PageHeader
      :title="editing ? 'Edit event' : 'New event'"
      :subtitle="editing ? event.name : null"
    />

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex-1 space-y-3 p-4">
        <FormSection title="Event">
          <FormField
            v-model="form.name"
            label="Name"
            :error="form.errors.name"
            required
          />

          <FormField
            v-model="form.badge_class"
            label="Badge Class"
            type="select"
            :options="badgeClasses"
            helper="PHP class used for badge generation"
            :error="form.errors.badge_class"
          />
        </FormSection>

        <FormSection
          title="Event Dates"
          description="Metadata only. These two dates gate nothing in the system; they are reference values shown on the website."
        >
          <FormField
            v-model="form.starts_at"
            label="Starts at"
            type="date"
            helper="First day of the convention. Display only."
            :error="form.errors.starts_at"
            required
            narrow
          />

          <FormField
            v-model="form.ends_at"
            label="Ends at"
            type="date"
            helper="Last day of the convention. Display only."
            :error="form.errors.ends_at"
            required
            narrow
          />
        </FormSection>

        <FormSection
          title="Order Management"
          description="The order window is the period in which attendees buy badges from us directly. Before it opens they can only get a badge through the registration system, as part of their registration payment, which is where we want the first badge paid."
        >
          <FormField
            v-model="form.order_starts_at"
            label="Order Window Start"
            type="datetime-local"
            helper="From this moment attendees order and pay for badges here. Before it, a badge has to be booked and paid through the registration system instead, so we do not have to collect a separate payment."
            :error="form.errors.order_starts_at"
            required
            narrow
          />

          <FormField
            v-model="form.order_ends_at"
            label="Order Window End"
            type="datetime-local"
            helper="After this, no further badge orders can be placed through us."
            :error="form.errors.order_ends_at"
            required
            narrow
          />

          <FormField
            v-model="form.free_badge_deadline"
            label="Free Badge Deadline"
            type="datetime-local"
            helper="Until when we honour the free badge included with registration. Only the first submission counts: a badge submitted before the deadline stays free even if it is rejected and resubmitted afterwards. Shown on the front page and the FAQ."
            :error="form.errors.free_badge_deadline"
            required
            narrow
          />

          <FormField
            v-model="form.mass_printed_at"
            label="Mass Print Date"
            type="datetime-local"
            helper="Cutoff for the bulk print run before the convention. Once it passes, badges are printed on site instead and the public pages tell attendees to collect them from the 2nd convention day."
            :error="form.errors.mass_printed_at"
            required
            narrow
          />
        </FormSection>

        <FormSection
          title="Catch-Em-All"
          description="The catch-the-fursuiter game. Its own feature, with its own window, independent of the gallery."
        >
          <FormField
            v-model="form.catch_em_all_enabled"
            label="Catch-Em-All Enabled"
            type="toggle"
            helper="Enable catch-em-all functionality for this event"
            :error="form.errors.catch_em_all_enabled"
          />

          <FormField
            v-model="form.catch_em_all_start"
            label="Catch-Em-All Start"
            type="datetime-local"
            helper="When the catch-em-all game should start (leave empty to start with event)"
            :error="form.errors.catch_em_all_start"
            narrow
          />

          <FormField
            v-model="form.catch_em_all_end"
            label="Catch-Em-All End"
            type="datetime-local"
            helper="When the catch-em-all game should end (leave empty to end with event)"
            :error="form.errors.catch_em_all_end"
            narrow
          />
        </FormSection>

        <FormSection title="Gallery Settings">
          <FormField
            v-model="form.archival_notice"
            label="Archival Notice"
            type="textarea"
            helper="Notice to display for archival/historical events"
            :error="form.errors.archival_notice"
          />
        </FormSection>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="editing ? 'Save changes' : 'Create'"
      />
    </form>
  </ManageLayout>
</template>
