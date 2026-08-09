<script setup>
/**
 * A badge. There is no create counterpart: that page has never been able to save
 * (rebuild-plan 2.10 #6), and no separate show page either, so this is also what a
 * reviewer opens from the list. `canEdit` is false for them: the two status selects render
 * as text like the other twelve fields and the save bar is gone, because the page is the
 * record rather than a form they can submit. The PUT refuses them independently.
 *
 * Almost every field is read-only, which is what the old badge list's form already was: twelve
 * of its fourteen fields were `->disabled()`. The two differences are both money-safety.
 *
 *  - `total` joins them. It rendered euros and had no inverse on write, so saving an
 *    unchanged badge wrote "3.00" into a cents column and turned 300 cents into 3
 *    (rebuild-plan 2.10 #3). Badge totals come from the checkout pipeline.
 *  - the two status selects offer only the transitions the state machines allow from the
 *    badge's current state, and the server runs `transitionTo()` rather than writing the
 *    string (rebuild-plan 2.10 #8). That is why the fulfillment list is short: from
 *    `pending` the only way on is `processing`.
 *
 * Read-only fields render as text rather than greyed-out inputs, per FormField: a disabled
 * box invites clicking something that can never change. The toggles keep their switch,
 * because the shape is the value.
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  badge: { type: Object, required: true },
  /** [{ value, label }] - the current state plus whatever it can transition to. */
  fulfillmentOptions: { type: Array, required: true },
  paymentOptions: { type: Array, required: true },
  /** Null when this operator may not delete. */
  deleteAction: { type: Object, default: null },
  /** False for a reviewer: the page renders as a record, not a form. */
  canEdit: { type: Boolean, default: false },
});

const statusLabel = (options, value) =>
  options.find((option) => option.value === value)?.label ?? value;

const form = useForm({
  status_fulfillment: props.badge.status_fulfillment,
  status_payment: props.badge.status_payment,
});

const title = computed(() => props.badge.custom_id ?? props.badge.fursuit ?? 'Badge');

const submit = () => form.put(route('admin.badges.update', props.badge.id));
</script>

<template>
  <Head :title="`${canEdit ? 'Edit badge' : 'Badge'} ${title}`" />

  <ManageLayout>
    <PageHeader :title="canEdit ? 'Edit badge' : 'Badge'" :subtitle="title">
      <template #actions>
        <ActionButton v-if="deleteAction" :action="deleteAction" />
      </template>
    </PageHeader>

    <form class="flex flex-col gap-3 p-4" @submit.prevent="submit">
      <FormSection title="Badge Information" description="Basic badge details and associated fursuit">
        <FormField
          label="Fursuit"
          :model-value="badge.fursuit"
          helper="The fursuit this badge belongs to (cannot be changed)"
          readonly
        />
        <FormField
          label="Badge ID"
          :model-value="badge.custom_id"
          helper="Unique badge identifier (auto-generated)"
          readonly
          mono
        />
        <FormField label="Species" :model-value="badge.species_name" helper="The fursuit species" readonly />
        <FormField label="Owner" :model-value="badge.owner_name" helper="The fursuit owner" readonly />
      </FormSection>

      <FormSection title="Status Management" description="Current fulfillment and payment status of the badge">
        <template v-if="canEdit">
          <FormField
            v-model="form.status_fulfillment"
            label="Fulfillment Status"
            type="select"
            :options="fulfillmentOptions"
            helper="Current fulfillment stage of the badge"
            :error="form.errors.status_fulfillment"
            required
            narrow
          />
          <FormField
            v-model="form.status_payment"
            label="Payment Status"
            type="select"
            :options="paymentOptions"
            helper="Current payment status"
            :error="form.errors.status_payment"
            required
            narrow
          />
        </template>
        <template v-else>
          <FormField
            label="Fulfillment Status"
            :model-value="statusLabel(fulfillmentOptions, badge.status_fulfillment)"
            helper="Current fulfillment stage of the badge"
            readonly
          />
          <FormField
            label="Payment Status"
            :model-value="statusLabel(paymentOptions, badge.status_payment)"
            helper="Current payment status"
            readonly
          />
        </template>
      </FormSection>

      <FormSection title="Pricing Details" description="Badge pricing breakdown and financial information">
        <FormField label="Total (€)" :model-value="badge.total" helper="Total amount in euros" readonly />
        <FormField label="Subtotal (€)" :model-value="badge.subtotal" helper="Amount before tax" readonly />
        <FormField label="Tax (€)" :model-value="badge.tax" helper="Tax amount" readonly />
        <FormField
          label="Free Badge"
          type="toggle"
          :model-value="badge.is_free_badge"
          helper="Whether this badge was provided for free"
          disabled
        />
        <FormField
          label="Extra Copy"
          type="toggle"
          :model-value="badge.extra_copy"
          helper="Whether this is an additional copy of another badge"
          disabled
        />
      </FormSection>

      <FormSection
        title="Badge Features &amp; Upgrades"
        description="Special features and upgrade options applied to this badge"
        collapsible
        collapsed
      >
        <FormField
          label="Double-Sided Print"
          type="toggle"
          :model-value="badge.dual_side_print"
          helper="Whether the badge is printed on both sides"
          disabled
        />
        <FormField
          label="Late Fee Applied"
          type="toggle"
          :model-value="badge.apply_late_fee"
          helper="Whether a late fee was applied to this badge"
          disabled
        />
      </FormSection>

      <FormSection
        title="Timeline &amp; Processing"
        description="Key dates and processing timestamps"
        collapsible
        collapsed
      >
        <FormField
          label="Created At"
          :model-value="badge.created_at"
          helper="When the badge was initially created"
          readonly
        />
        <FormField
          label="Printed At"
          :model-value="badge.printed_at"
          helper="When the badge was printed"
          readonly
        />
        <FormField
          label="Picked Up At"
          :model-value="badge.picked_up_at"
          helper="When the badge was collected by the owner"
          readonly
        />
      </FormSection>

      <FormActions v-if="canEdit" :processing="form.processing" :dirty="form.isDirty" submit-label="Save changes" />
    </form>
  </ManageLayout>
</template>
