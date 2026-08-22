<script setup>
/**
 * Create and edit for a user, as a page rather than the old panel modal a ManageRecords
 * page gave it.
 *
 * The field order is the old user list's schema, minus `valid_registration`: it was a Toggle
 * for a column that no longer exists on `users`, and it is why saving this form throws
 * SQL 1054 today. It is not here, and UserRequest does not accept it
 * either, so a stale client cannot resurrect it.
 *
 * Inside SettingsLayout, like the list it is reached from: Users is a Settings pane, so the
 * submenu stays beside the form and the header keeps saying where in Settings this is.
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';

const props = defineProps({
  /** null on create. */
  user: { type: Object, default: null },
  /** What we have mailed this person, newest first. Empty on create. */
  sentNotifications: { type: Array, default: () => [] },
});

const editing = computed(() => Boolean(props.user?.id));

/*
 * SettingsLayout titles every pane "Settings", so the subtitle is the only place this
 * screen can say which account it is editing; "Users" alone would repeat the submenu.
 */
const subtitle = computed(() => (editing.value ? `Users / ${props.user.name}` : 'Users / New user'));

const form = useForm({
  remote_id: props.user?.remote_id ?? '',
  name: props.user?.name ?? '',
  email: props.user?.email ?? '',
  avatar: props.user?.avatar ?? '',
  is_reviewer: props.user?.is_reviewer ?? false,
  is_admin: props.user?.is_admin ?? false,
});

const submit = () => {
  if (editing.value) {
    form.put(route('admin.settings.users.update', props.user.id));

    return;
  }

  form.post(route('admin.settings.users.store'));
};
</script>

<template>
  <Head :title="editing ? 'Edit user' : 'New user'" />

  <SettingsLayout :subtitle="subtitle" flush>
    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex-1 space-y-3 p-4">
        <FormSection title="User">
          <FormField
            v-model="form.remote_id"
            label="Remote id"
            :error="form.errors.remote_id"
            required
          />

          <FormField
            v-model="form.name"
            label="Name"
            :error="form.errors.name"
            required
          />

          <FormField
            v-model="form.email"
            label="Email"
            type="email"
            :error="form.errors.email"
            required
          />

          <FormField
            v-model="form.avatar"
            label="Avatar"
            type="textarea"
            :error="form.errors.avatar"
          />

          <FormField
            v-model="form.is_reviewer"
            label="Is reviewer"
            type="toggle"
            :error="form.errors.is_reviewer"
            required
          />

          <FormField
            v-model="form.is_admin"
            label="Is admin"
            type="toggle"
            :error="form.errors.is_admin"
            required
          />
        </FormSection>

        <!-- Read-only, and outside the form on purpose: it is a record of what happened, not a
             field. The desk's question is "did they hear from us", usually about a badge waiting
             at the counter, and until this existed the only answer was the mail server's log. -->
        <FormSection
          v-if="editing"
          title="Emails sent"
          description="What we have sent this account, newest first. The 25 most recent."
        >
          <ul v-if="sentNotifications.length" class="divide-y divide-hairline">
            <li
              v-for="sent in sentNotifications"
              :key="sent.id"
              class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 py-2"
            >
              <span class="text-[13px] font-semibold">{{ sent.label }}</span>
              <span class="text-[12px] text-fg-3 tabular-nums" :title="sent.sentAtIso">{{ sent.sentAt }}</span>
              <span v-if="sent.subject" class="w-full text-[12px] text-fg-2">{{ sent.subject }}</span>
            </li>
          </ul>

          <p v-else class="py-2 text-[13px] text-fg-2">
            Nothing sent to this account yet.
          </p>
        </FormSection>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="editing ? 'Save changes' : 'Create'"
      />
    </form>
  </SettingsLayout>
</template>
