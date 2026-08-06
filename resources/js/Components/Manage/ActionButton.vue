<script setup>
/**
 * Renders one server-declared action.
 *
 * The client decides nothing: the server has already resolved whether the action is
 * offered at all, whether it is disabled and why, what the confirm modal says, and which
 * fields to collect first. GET actions are Inertia links; everything else submits a
 * form and lands back on the page with a flashed toast.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Dialog from 'primevue/dialog';
import ManageIcon from './ManageIcon.vue';
import { resolve, toneButton } from './tones.js';

const props = defineProps({
  action: { type: Object, required: true },
  /** Extra payload merged into the request, e.g. { ids: [1, 2] } for bulk actions. */
  data: { type: Object, default: () => ({}) },
  iconOnly: { type: Boolean, default: false },
});

const open = ref(false);
const processing = ref(false);
const form = reactive({});

const classes = computed(() => resolve(toneButton, props.action.tone, 'info'));
const needsDialog = computed(() => Boolean(props.action.confirm || props.action.fields));
const disabled = computed(() => Boolean(props.action.disabledReason));

watch(open, (isOpen) => {
  if (!isOpen) {
    return;
  }

  for (const field of props.action.fields ?? []) {
    form[field.key] = field.default ?? '';
  }
});

/*
 * router.visit rather than router[method]: router.delete takes (url, options), not
 * (url, data, options) the way post/put/patch do. Called through the shorthand, a delete
 * swallowed the payload as its options object and dropped the real options entirely, so
 * a bulk delete sent no ids, a field-carrying delete sent no fields, and onFinish never
 * ran - which left processing and the dialog stuck open forever. visit() has one shape
 * for every method, so the difference cannot come back.
 */
const submit = () => {
  processing.value = true;

  router.visit(props.action.url, {
    method: props.action.method,
    data: { ...props.data, ...form },
    preserveScroll: true,
    onFinish: () => {
      processing.value = false;
      open.value = false;
    },
  });
};

const activate = () => {
  if (disabled.value) {
    return;
  }

  if (needsDialog.value) {
    open.value = true;

    return;
  }

  submit();
};

const base =
  'inline-flex h-7 items-center gap-1.5 rounded border px-2 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40';

/*
 * PrimeVue runs unstyled with the local Lara preset, so a <Dialog> carries the preset's
 * Tailwind utilities rather than p-* hooks. A component-level `pt` replaces the preset's
 * classes for the sections named here (mergeProps is off by default). `manage-surface` on
 * the mask re-points the token block for the overlay, which is teleported to <body> and so
 * sits outside the .manage subtree. Same arrangement as posDialog.js.
 */
const dialogPt = {
  mask: { class: ['manage-surface', 'p-5', 'bg-black/50'] },
  root: {
    class: [
      'flex flex-col',
      'm-0 max-h-[90vh] w-[28rem] max-w-full',
      'rounded-md border border-hairline bg-mg-surface-1 text-fg-1 shadow-xl',
    ],
  },
  header: { class: ['flex shrink-0 items-center justify-between gap-3 border-b border-hairline px-4 py-3'] },
  title: { class: ['text-base font-medium text-fg-1'] },
  content: { class: ['flex flex-col gap-3 overflow-y-auto px-4 py-3'] },
  footer: { class: ['flex shrink-0 items-center justify-end gap-2 border-t border-hairline px-4 py-3'] },
  closeButton: {
    class: ['inline-flex size-6 items-center justify-center rounded text-fg-3 transition-colors hover:bg-mg-surface-3 hover:text-fg-1'],
  },
  closeButtonIcon: { class: ['inline-block', 'w-4', 'h-4'] },
};
</script>

<template>
  <a
    v-if="action.method === 'get' && action.newTab"
    :href="action.url"
    target="_blank"
    rel="noopener"
    :class="[base, classes]"
    :title="action.label"
  >
    <ManageIcon v-if="action.icon" :name="action.icon" />
    <span v-if="!iconOnly">{{ action.label }}</span>
  </a>

  <Link
    v-else-if="action.method === 'get'"
    :href="action.url"
    :class="[base, classes]"
    :title="action.label"
  >
    <ManageIcon v-if="action.icon" :name="action.icon" />
    <span v-if="!iconOnly">{{ action.label }}</span>
  </Link>

  <button
    v-else
    type="button"
    :class="[base, classes]"
    :disabled="disabled || processing"
    :title="action.disabledReason ?? action.label"
    @click.stop="activate"
  >
    <ManageIcon v-if="action.icon" :name="action.icon" />
    <span v-if="!iconOnly">{{ action.label }}</span>
  </button>

  <Dialog
    v-model:visible="open"
    modal
    dismissable-mask
    :draggable="false"
    :header="action.confirm?.heading ?? action.label"
    :pt="dialogPt"
  >
    <p v-if="action.confirm?.description" class="text-[13px] text-fg-2">
      {{ action.confirm.description }}
    </p>

    <div v-if="action.fields" class="flex flex-col gap-3">
      <label v-for="field in action.fields" :key="field.key" class="flex flex-col gap-1">
        <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">{{ field.label }}</span>

        <select
          v-if="field.type === 'select'"
          v-model="form[field.key]"
          class="h-8 rounded border border-hairline bg-mg-surface-2 px-2 text-[13px] text-fg-1"
          :required="field.required"
        >
          <option v-for="option in field.options" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>

        <textarea
          v-else-if="field.type === 'textarea'"
          v-model="form[field.key]"
          rows="3"
          class="rounded border border-hairline bg-mg-surface-2 px-2 py-1.5 text-[13px] text-fg-1"
          :required="field.required"
        />

        <input
          v-else
          v-model="form[field.key]"
          :type="field.type === 'number' ? 'number' : 'text'"
          class="h-8 rounded border border-hairline bg-mg-surface-2 px-2 text-[13px] text-fg-1"
          :required="field.required"
        />

        <span v-if="field.helper" class="text-[11px] text-fg-3">{{ field.helper }}</span>
      </label>
    </div>

    <template #footer>
      <button
        type="button"
        class="h-8 rounded border border-hairline px-3 text-[13px] text-fg-2 transition-colors hover:bg-mg-surface-3"
        @click="open = false"
      >
        Cancel
      </button>
      <button
        type="button"
        class="h-8 rounded px-3 text-[13px] font-medium text-mg-surface-0"
        :class="action.tone === 'danger' ? 'bg-state-danger' : 'bg-state-live'"
        :disabled="processing"
        @click="submit"
      >
        {{ action.confirm?.submit ?? action.label }}
      </button>
    </template>
  </Dialog>
</template>
