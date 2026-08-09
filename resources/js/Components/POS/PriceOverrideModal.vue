<script setup>
/*
 * Overriding what a badge costs.
 *
 * Used from two places with the same shape: the payment screen passes every
 * line of the open transaction, the badge editor passes one badge. Either way
 * a manager approves once and the server reprices all of them together, so a
 * two-badge correction is one approval and one reopened transaction rather
 * than two of each.
 *
 * Prices are held in cents everywhere and shown in euro here, because the desk
 * says "four fifty" and nobody at a counter thinks in cents.
 */
import { computed, ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import { useForm, usePage } from '@inertiajs/vue3';
import { posDialogPt } from '@/Components/POS/posDialog.js';
import ManagerApprovalField from '@/Components/POS/ManagerApprovalField.vue';
import { formatEuroFromCents } from '@/helpers.js';

const props = defineProps({
    show: Boolean,
    // [{ id, label, sublabel, total }] — total in cents.
    items: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['close']);

const page = usePage();

// A manager working their own till approves by being signed in. Everyone else
// gets the field. The server re-checks this either way; hiding the field is a
// convenience, not the rule.
const needsApproval = computed(() => ! page.props.auth?.user?.is_manager);

const form = useForm({
    prices: {},
    manager_code: '',
    reason: '',
});

// Euro strings are the edit buffer; cents are what leaves. Kept separate so a
// half-typed "4." does not round-trip through a number and lose the decimal.
const euros = ref({});

function centsToEuro(cents) {
    return ((cents ?? 0) / 100).toFixed(2);
}

function euroToCents(value) {
    const parsed = Number.parseFloat(String(value).replace(',', '.'));

    return Number.isFinite(parsed) ? Math.round(parsed * 100) : null;
}

watch(() => props.show, (visible) => {
    if (! visible) {
        return;
    }

    form.clearErrors();
    form.manager_code = '';
    form.reason = '';
    euros.value = Object.fromEntries(props.items.map((item) => [item.id, centsToEuro(item.total)]));
}, { immediate: true });

const parsed = computed(() => props.items.map((item) => ({
    ...item,
    cents: euroToCents(euros.value[item.id]),
})));

const invalid = computed(() => parsed.value.some((item) => item.cents === null || item.cents < 0));

const newTotal = computed(() => parsed.value.reduce((sum, item) => sum + (item.cents ?? 0), 0));

const oldTotal = computed(() => props.items.reduce((sum, item) => sum + (item.total ?? 0), 0));

const changed = computed(() => newTotal.value !== oldTotal.value);

// The server reports per-badge problems under `prices.<id>`; the shared ones
// (nothing found, a paid badge in the set) come back on `prices` itself.
const priceError = computed(() => form.errors.prices);

function setFree(item) {
    euros.value[item.id] = '0.00';
}

function submit() {
    if (invalid.value) {
        return;
    }

    form
        .transform((data) => ({
            ...data,
            prices: Object.fromEntries(parsed.value.map((item) => [item.id, item.cents])),
        }))
        .post(route('pos.badges.prices'), {
            preserveScroll: true,
            // Repricing voids the transaction and lands on a *different* one.
            // Inertia's form helper preserves component state by default, which
            // would keep the old checkout's id baked into the payment forms and
            // the old lines on screen. This page must be rebuilt from scratch.
            preserveState: false,
            onSuccess: () => emit('close'),
        });
}
</script>

<template>
    <Dialog
        :visible="show"
        modal
        :closable="false"
        header="Override price"
        :style="{ width: '34rem' }"
        :pt="posDialogPt"
    >
        <div class="flex flex-col gap-3">
            <div v-for="item in items" :key="item.id" class="pos-card">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-semibold truncate">{{ item.label }}</div>
                        <div v-if="item.sublabel" class="pos-num text-xs text-pos-muted">
                            {{ item.sublabel }}
                        </div>
                        <div class="text-xs text-pos-muted">
                            Currently {{ formatEuroFromCents(item.total ?? 0) }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="pos-btn pos-btn--sm" @click="setFree(item)">
                            Free
                        </button>
                        <div class="relative">
                            <input
                                v-model="euros[item.id]"
                                type="text"
                                inputmode="decimal"
                                class="pos-field pos-field--sm w-32"
                                :class="form.errors[`prices.${item.id}`] ? 'border-pos-bad' : ''"
                            />
                        </div>
                        <span class="text-pos-muted">€</span>
                    </div>
                </div>
                <p v-if="form.errors[`prices.${item.id}`]" class="text-pos-bad text-sm mt-1">
                    {{ form.errors[`prices.${item.id}`] }}
                </p>
            </div>

            <p v-if="priceError" class="text-pos-bad text-sm">{{ priceError }}</p>

            <div v-if="items.length > 1" class="pos-card flex items-center justify-between">
                <span class="pos-label">New total</span>
                <span class="pos-num text-xl font-bold">{{ formatEuroFromCents(newTotal) }}</span>
            </div>

            <div class="pos-card">
                <label class="pos-label block mb-1" for="override-reason">Reason (optional)</label>
                <input
                    id="override-reason"
                    v-model="form.reason"
                    type="text"
                    maxlength="255"
                    class="pos-field pos-field--sm"
                    placeholder="Goodwill, damaged card, wrong price"
                />
            </div>

            <ManagerApprovalField
                v-model="form.manager_code"
                :show="needsApproval"
                :error="form.errors.manager_code"
                @submit="submit"
            />

            <!--
                Said out loud, because the operator is about to watch the screen
                they were on disappear: repricing voids the signed transaction and
                opens a new one at the new total. Nothing is paid twice.
            -->
            <p class="text-pos-muted text-xs">
                Changing a price cancels the open transaction and reopens it at the new
                total. Badges that are already paid cannot be repriced.
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
                    :disabled="invalid || ! changed || form.processing"
                    @click="submit"
                >
                    {{ form.processing ? 'Saving…' : 'Apply override' }}
                </button>
            </div>
        </template>
    </Dialog>
</template>
