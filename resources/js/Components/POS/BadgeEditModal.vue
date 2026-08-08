<script setup>
/*
 * Fixing a badge at the desk.
 *
 * Reached from the attendee screen by picking exactly one badge, which keeps it
 * off the row itself: this is the rare correction, not the hourly action, and a
 * third button on every card would sit next to Print and Hand out all day
 * waiting to be pressed by mistake.
 *
 * The details are a cashier's job - the attendee is standing there saying what
 * is wrong. The price is not: it opens the override dialog, which needs a
 * manager. Two authorities, so two buttons, rather than one Save that means
 * different things depending on which field was touched.
 */
import { ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import { useForm } from '@inertiajs/vue3';
import { posDialogPt } from '@/Components/POS/posDialog.js';
import { formatEuroFromCents } from '@/helpers.js';

const props = defineProps({
    show: Boolean,
    badge: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close', 'override-price']);

const form = useForm({
    name: '',
    species: '',
    dual_side_print: false,
    published: false,
    catch_em_all: false,
});

watch(() => [props.show, props.badge], () => {
    if (! props.show || ! props.badge) {
        return;
    }

    form.clearErrors();
    form.name = props.badge.fursuit?.name ?? '';
    form.species = props.badge.fursuit?.species?.name ?? '';
    form.dual_side_print = Boolean(props.badge.dual_side_print);
    form.published = Boolean(props.badge.fursuit?.published);
    form.catch_em_all = Boolean(props.badge.fursuit?.catch_em_all);
}, { immediate: true });

const printedWarning = ref(false);

watch(() => props.badge, (badge) => {
    printedWarning.value = Boolean(badge?.printed_at);
}, { immediate: true });

function submit() {
    form.put(route('pos.badges.update', { badge: props.badge.id }), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Dialog
        v-if="badge"
        :visible="show"
        modal
        :closable="false"
        :header="`Edit ${badge.custom_id || `#${badge.id}`}`"
        :style="{ width: '34rem' }"
        :pt="posDialogPt"
    >
        <div class="flex flex-col gap-3">
            <div class="pos-card">
                <label class="pos-label block mb-1" for="badge-name">Fursuit name</label>
                <input
                    id="badge-name"
                    v-model="form.name"
                    type="text"
                    maxlength="32"
                    class="pos-field pos-field--sm"
                    :class="form.errors.name ? 'border-pos-bad' : ''"
                />
                <p v-if="form.errors.name" class="text-pos-bad text-sm mt-1">{{ form.errors.name }}</p>
            </div>

            <div class="pos-card">
                <label class="pos-label block mb-1" for="badge-species">Species</label>
                <input
                    id="badge-species"
                    v-model="form.species"
                    type="text"
                    maxlength="32"
                    class="pos-field pos-field--sm"
                    :class="form.errors.species ? 'border-pos-bad' : ''"
                />
                <p v-if="form.errors.species" class="text-pos-bad text-sm mt-1">{{ form.errors.species }}</p>
            </div>

            <div class="pos-card flex flex-col gap-2">
                <label class="flex items-center justify-between gap-3">
                    <span>Double sided print</span>
                    <input v-model="form.dual_side_print" type="checkbox" class="w-6 h-6" />
                </label>
                <label class="flex items-center justify-between gap-3">
                    <span>Show in public gallery</span>
                    <input v-model="form.published" type="checkbox" class="w-6 h-6" />
                </label>
                <label class="flex items-center justify-between gap-3">
                    <span>Catch-Em-All</span>
                    <input v-model="form.catch_em_all" type="checkbox" class="w-6 h-6" />
                </label>
            </div>

            <!-- Money is a different authority, so it is a different button. -->
            <div class="pos-card flex items-center justify-between gap-3">
                <div>
                    <span class="pos-label block">Price</span>
                    <span class="pos-num text-xl font-bold">
                        {{ formatEuroFromCents(badge.total ?? 0) }}
                    </span>
                    <span v-if="badge.status_payment !== 'unpaid'" class="text-pos-muted text-xs block">
                        Already paid — cannot be repriced
                    </span>
                </div>
                <button
                    type="button"
                    class="pos-btn"
                    :disabled="badge.status_payment !== 'unpaid'"
                    @click="emit('override-price', badge)"
                >
                    Override price
                </button>
            </div>

            <p v-if="printedWarning" class="text-pos-warn text-sm">
                This badge has already been printed. Changing the name or species means
                the printed card no longer matches — reprint it after saving.
            </p>
        </div>

        <template #footer>
            <div class="grid grid-cols-2 gap-2 w-full">
                <button type="button" class="pos-btn pos-btn--commit w-full" @click="emit('close')">
                    Cancel
                </button>
                <button
                    type="button"
                    class="pos-btn pos-btn--commit pos-btn--primary w-full"
                    :disabled="form.processing"
                    @click="submit"
                >
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
                </button>
            </div>
        </template>
    </Dialog>
</template>
