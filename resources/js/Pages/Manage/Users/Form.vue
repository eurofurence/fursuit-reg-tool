<script setup>
/**
 * Create and edit for a user, as a page rather than the Filament modal a ManageRecords
 * page gave it (plan 1.2).
 *
 * The field order is UserResource's schema, minus `valid_registration`: it was a Toggle
 * for a column that no longer exists on `users`, and it is why saving this form throws
 * SQL 1054 today (plan 2.10 change 4). It is not here, and UserRequest does not accept it
 * either, so a stale client cannot resurrect it.
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
  user: { type: Object, default: null },
});

const editing = computed(() => Boolean(props.user?.id));

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
    form.put(route('manage.users.update', props.user.id));

    return;
  }

  form.post(route('manage.users.store'));
};
</script>

<template>
  <Head :title="editing ? 'Edit user' : 'New user'" />

  <ManageLayout>
    <PageHeader
      :title="editing ? 'Edit user' : 'New user'"
      :subtitle="editing ? user.name : null"
    />

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
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="editing ? 'Save changes' : 'Create'"
      />
    </form>
  </ManageLayout>
</template>
