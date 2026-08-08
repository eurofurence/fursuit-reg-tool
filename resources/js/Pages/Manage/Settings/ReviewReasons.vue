<script setup>
/**
 * Settings > Review Reasons.
 *
 * The wording the review queue offers and the attendee receives, owned by the desk. It used to
 * be a PHP constant, so fixing a typo in something thousands of attendees read meant a pull
 * request during the convention - by the people least able to ship one, since they were working
 * the queue.
 *
 * Two fields per reason, and the split is the point:
 *
 *  - **Keyword** is what the queue puts on a chip. A reviewer picking from eleven options scans
 *    them; the picker used to show the paragraphs, which made it a wall of text.
 *  - **Full text** is what the attendee reads. It is edited here at length, and the reviewer can
 *    still adjust it in the queue before it goes out.
 *
 * One list per outcome, because the two say opposite things: a rejection asks the attendee to fix
 * their badge, a publication block tells them their badge is fine and is being printed. A reason
 * cannot be moved between them - it would be the wrong sentence in the wrong place.
 *
 * Each row is its own form. They fail separately, and Inertia's error bag is per request, so one
 * rejected row must not throw away what was typed into another.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';

const props = defineProps({
  canEdit: { type: Boolean, default: false },
  /** [{ value, label, consequence, tone, reasons: [...] }] - one entry per verdict with a reason. */
  outcomes: { type: Array, default: () => [] },
  storeUrl: { type: String, required: true },
  restoreUrl: { type: String, required: true },
});

/*
 * One draft per row, keyed by id, created on demand. Not a single "editing" object: an operator
 * fixing the wording of three reasons should not lose two of them by opening the third.
 */
const drafts = reactive({});

const draftFor = (reason) => {
  if (!drafts[reason.id]) {
    drafts[reason.id] = {
      keyword: reason.keyword,
      body: reason.body,
      sort_order: reason.sortOrder,
      is_active: reason.isActive,
      saving: false,
    };
  }

  return drafts[reason.id];
};

const dirty = (reason) => {
  const draft = drafts[reason.id];

  if (!draft) {
    return false;
  }

  return draft.keyword !== reason.keyword
    || draft.body !== reason.body
    || draft.sort_order !== reason.sortOrder
    || draft.is_active !== reason.isActive;
};

const save = (reason) => {
  const draft = draftFor(reason);
  draft.saving = true;

  router.put(reason.updateUrl, {
    keyword: draft.keyword,
    body: draft.body,
    sort_order: draft.sort_order,
    is_active: draft.is_active,
  }, {
    preserveScroll: true,
    onFinish: () => {
      draft.saving = false;
    },
    onSuccess: () => {
      delete drafts[reason.id];
    },
  });
};

const revert = (reason) => {
  delete drafts[reason.id];
};

const remove = (reason) => {
  if (!window.confirm(`Delete "${reason.keyword}"? Deactivate it instead if it has ever been sent.`)) {
    return;
  }

  router.delete(reason.destroyUrl, { preserveScroll: true });
};

/* A new reason, per outcome: the form belongs to the list it adds to. */
const adding = ref(null);
const addForm = useForm({ outcome: '', keyword: '', body: '' });

const startAdd = (outcome) => {
  addForm.reset();
  addForm.outcome = outcome.value;
  adding.value = outcome.value;
};

const submitAdd = () => {
  addForm.post(props.storeUrl, {
    preserveScroll: true,
    onSuccess: () => {
      adding.value = null;
      addForm.reset();
    },
  });
};

const restoreDefaults = () => {
  router.post(props.restoreUrl, {}, { preserveScroll: true });
};

const activeCount = (outcome) => outcome.reasons.filter((reason) => reason.isActive).length;

const toneText = {
  ok: 'text-state-ok',
  warn: 'text-state-warn',
  danger: 'text-state-danger',
  info: 'text-state-live',
  idle: 'text-fg-2',
};

const control =
  'h-8 w-full rounded border border-hairline bg-mg-surface-2 px-2 text-[13px] text-fg-1 outline-none focus:border-state-live/50 disabled:opacity-60';

const textarea =
  'w-full rounded border border-hairline bg-mg-surface-2 px-2 py-1.5 text-[12px] leading-[17px] text-fg-1 outline-none focus:border-state-live/50 disabled:opacity-60';
</script>

<template>
  <Head title="Review Reasons" />

  <SettingsLayout>
    <template #actions>
      <button
        v-if="canEdit"
        type="button"
        class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] font-medium text-fg-1 transition-colors hover:bg-mg-surface-3"
        @click="restoreDefaults"
      >
        <ManageIcon name="rotate-ccw" />
        Restore missing defaults
      </button>
    </template>

    <FormSection
      v-for="outcome in outcomes"
      :key="outcome.value"
      :title="outcome.label"
      :description="outcome.consequence"
    >
      <p class="text-[11px] text-fg-3">
        {{ activeCount(outcome) }} of {{ outcome.reasons.length }} offered in the queue.
        The keyword is the chip a reviewer clicks; the full text is what the attendee reads.
      </p>

      <div class="mt-2 flex flex-col gap-2">
        <article
          v-for="reason in outcome.reasons"
          :key="reason.id"
          class="rounded border border-hairline bg-mg-surface-1 px-3 py-2"
          :class="reason.isActive ? '' : 'opacity-60'"
        >
          <div class="flex flex-wrap items-center gap-2">
            <label class="flex min-w-48 flex-1 flex-col gap-1">
              <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Keyword</span>
              <input
                v-model="draftFor(reason).keyword"
                type="text"
                maxlength="60"
                :class="control"
                :disabled="!canEdit"
              />
            </label>

            <label class="flex w-24 flex-col gap-1">
              <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Order</span>
              <input
                v-model.number="draftFor(reason).sort_order"
                type="number"
                min="0"
                :class="control"
                :disabled="!canEdit"
              />
            </label>

            <!--
              Deactivating is how a reason is retired: the slug stays resolvable in a request log
              while the queue stops offering it. The server refuses to leave an outcome with none.
            -->
            <label class="mt-4 flex items-center gap-1.5 text-[12px] text-fg-2">
              <input
                v-model="draftFor(reason).is_active"
                type="checkbox"
                :disabled="!canEdit"
              />
              Offered
            </label>

            <span class="mt-4 font-mono text-[11px] text-fg-3">{{ reason.slug }}</span>
          </div>

          <label class="mt-2 flex flex-col gap-1">
            <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">
              Full text sent to the attendee
            </span>
            <textarea
              v-model="draftFor(reason).body"
              rows="3"
              maxlength="2000"
              :class="textarea"
              :disabled="!canEdit"
            />
          </label>

          <div v-if="canEdit" class="mt-2 flex items-center gap-2">
            <button
              type="button"
              class="h-7 rounded bg-state-live px-2.5 text-[12px] font-medium text-mg-surface-0 disabled:opacity-40"
              :disabled="!dirty(reason) || draftFor(reason).saving"
              @click="save(reason)"
            >
              Save
            </button>
            <button
              type="button"
              class="h-7 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-mg-surface-3 disabled:opacity-40"
              :disabled="!dirty(reason)"
              @click="revert(reason)"
            >
              Revert
            </button>
            <button
              type="button"
              class="ml-auto h-7 rounded border border-state-danger/35 px-2 text-[12px] text-state-danger transition-colors hover:bg-state-danger/12"
              @click="remove(reason)"
            >
              Delete
            </button>
          </div>
        </article>

        <!-- Add. Scoped to this list, because a reason belongs to one verdict. -->
        <div v-if="canEdit">
          <button
            v-if="adding !== outcome.value"
            type="button"
            class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] font-medium text-fg-1 transition-colors hover:bg-mg-surface-3"
            @click="startAdd(outcome)"
          >
            <ManageIcon name="plus" />
            Add a reason
          </button>

          <div v-else class="rounded border border-state-live/40 bg-mg-surface-1 px-3 py-2">
            <label class="flex flex-col gap-1">
              <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Keyword</span>
              <input v-model="addForm.keyword" type="text" maxlength="60" :class="control" />
              <span v-if="addForm.errors.keyword" class="text-[11px] text-state-danger">
                {{ addForm.errors.keyword }}
              </span>
            </label>

            <label class="mt-2 flex flex-col gap-1">
              <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">
                Full text sent to the attendee
              </span>
              <textarea v-model="addForm.body" rows="3" maxlength="2000" :class="textarea" />
              <span v-if="addForm.errors.body" class="text-[11px] text-state-danger">
                {{ addForm.errors.body }}
              </span>
            </label>

            <div class="mt-2 flex items-center gap-2">
              <button
                type="button"
                class="h-7 rounded bg-state-live px-2.5 text-[12px] font-medium text-mg-surface-0"
                :disabled="addForm.processing"
                @click="submitAdd"
              >
                Add
              </button>
              <button
                type="button"
                class="h-7 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-mg-surface-3"
                @click="adding = null"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      </div>
    </FormSection>

    <p v-if="!canEdit" class="text-[11px] text-fg-3">
      Read-only: changing what attendees are told is an administrator's call.
    </p>
  </SettingsLayout>
</template>
