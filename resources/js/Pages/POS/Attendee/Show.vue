<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useForm } from 'laravel-precognition-vue-inertia';

import POSLayout from '@/Layouts/POSLayout.vue';
import ConfirmModal from '@/Components/POS/ConfirmModal.vue';
import BadgeCard from '@/Components/POS/Attendee/BadgeCard.vue';
import BadgeActionSheet from '@/Components/POS/Attendee/BadgeActionSheet.vue';
import AttendeeDetailsSheet from '@/Components/POS/Attendee/AttendeeDetailsSheet.vue';
import { badgeAction, isHandoutable, isPayable } from '@/Components/POS/Attendee/badgeAction.js';
import { usePosKeyboard } from '@/composables/usePosKeyboard';
import { formatEuroFromCents } from '@/helpers.js';

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    badges: Array,
    fursuits: Array,
    transactions: Array,
    checkouts: Array,
    attendee: Object,
    pastEventBadges: Array,
    currentEvent: Object,
    eventUser: Object,
});

/* --- Selection ------------------------------------------------------------
 * Tapping the body of a badge picks it for the bulk actions; the button on its
 * right always acts on that badge alone. With nothing picked, the bulk actions
 * mean "all of them" — the commit bar says so in words rather than leaving it
 * as a rule the staff have to know.
 */
const selectedIds = ref([]);

function toggleSelect(badge) {
    const index = selectedIds.value.indexOf(badge.id);
    if (index === -1) {
        selectedIds.value.push(badge.id);
    } else {
        selectedIds.value.splice(index, 1);
    }
}

function isSelected(badge) {
    return selectedIds.value.includes(badge.id);
}

const hasSelection = computed(() => selectedIds.value.length > 0);

function scope(predicate) {
    const pool = props.badges.filter(predicate);

    return hasSelection.value ? pool.filter((badge) => selectedIds.value.includes(badge.id)) : pool;
}

const payTargets = computed(() => scope(isPayable));
const handoutTargets = computed(() => scope(isHandoutable));

// A credit balance is not a debt: never show "-0,00 €" or a negative amount due.
const amountDue = computed(() => Math.max(0, (props.attendee.wallet?.balance ?? 0) * -1));

// With a selection the bar prices exactly what is selected; without one it
// shows the wallet's open balance, which is the number the attendee was told.
const payTotal = computed(() =>
    hasSelection.value ? payTargets.value.reduce((sum, badge) => sum + (badge.total ?? 0), 0) : amountDue.value
);

const openCount = computed(() => props.badges.filter((badge) => badgeAction(badge) !== null).length);

// Flattened so earlier conventions render as ordinary rows of the one list,
// each carrying the event it belongs to.
const olderBadges = computed(() =>
    (props.pastEventBadges ?? []).flatMap((entry) =>
        entry.badges.map((badge) => ({ badge, eventName: entry.event.name }))
    )
);

/* --- Confirmations --------------------------------------------------------
 * One dialog for every step that costs something, so Enter always has exactly
 * one meaning and a single guard covers the keyboard, the row buttons and the
 * ⋮ sheet alike.
 */
const confirm = ref(null);

function askPrint(badge) {
    confirm.value = {
        kind: 'print',
        badge,
        title: badge.printed_at ? 'Reprint badge' : 'Print badge',
        message: `Queue a print job for ${badge.fursuit?.name || 'this badge'} (${badge.custom_id || badge.id})?`,
        acceptLabel: 'Print',
    };
}

function askHandout(badge) {
    confirm.value = {
        kind: 'handout',
        badge,
        title: 'Hand out badge',
        message: `Mark ${badge.fursuit?.name || 'this badge'} (${badge.custom_id || badge.id}) as picked up?`,
        acceptLabel: 'Hand out',
    };
}

function askUndo(badge) {
    confirm.value = {
        kind: 'undo',
        badge,
        title: 'Undo handout',
        message: `Put ${badge.fursuit?.name || 'this badge'} back to ready for pickup?`,
        acceptLabel: 'Undo',
        acceptSeverity: 'danger',
    };
}

function askBulkHandout() {
    if (handoutTargets.value.length === 0) {
        return;
    }

    confirm.value = {
        kind: 'handout-bulk',
        title: `Hand out ${handoutTargets.value.length} badge(s)`,
        message: hasSelection.value
            ? 'Mark the selected badges as picked up?'
            : 'Mark every badge that is ready as picked up?',
        acceptLabel: 'Hand out',
    };
}

function runConfirm() {
    const pending = confirm.value;
    if (! pending) {
        return;
    }

    // Cleared first: a second confirm arriving before the dialog closes would
    // otherwise fire the same transition twice.
    confirm.value = null;

    switch (pending.kind) {
        case 'print':
            useForm('POST', route('pos.badges.print', { badge: pending.badge.id }), {})
                .submit({ preserveScroll: true });
            break;
        case 'handout':
            useForm('POST', route('pos.badges.handout', { badge: pending.badge.id }), {})
                .submit({ preserveScroll: true });
            break;
        case 'undo':
            useForm('POST', route('pos.badges.handout.undo', { badge: pending.badge.id }), {})
                .submit({ preserveScroll: true });
            break;
        case 'handout-bulk':
            useForm('POST', route('pos.badges.handout.bulk'), {
                badge_ids: handoutTargets.value.map((badge) => badge.id),
            }).submit({ preserveScroll: true });
            selectedIds.value = [];
            break;
    }
}

/* --- Money ---------------------------------------------------------------- */

function startPayment(badgeIds = null) {
    const ids = badgeIds ?? payTargets.value.map((badge) => badge.id);

    router.post(route('pos.checkout.store', {
        user_id: props.attendee.id,
        badge_ids: ids,
    }));
}

/* --- Sheets --------------------------------------------------------------- */

const sheetBadge = ref(null);
const showDetails = ref(false);

function openSheet(badge) {
    sheetBadge.value = badge;
}

function closeSheet() {
    sheetBadge.value = null;
}

// The sheet is a menu, not an actor: it closes and hands the badge to the same
// handler the row buttons use, so every path ends in the same confirmation.
function fromSheet(handler) {
    return (badge) => {
        closeSheet();
        handler(badge);
    };
}

function runBadgeAction({ badge, action }) {
    if (action === 'pay') {
        startPayment([badge.id]);
    } else if (action === 'print') {
        askPrint(badge);
    } else if (action === 'handout') {
        askHandout(badge);
    }
}

/* --- Keyboard -------------------------------------------------------------
 * Handlers are named so they can be removed again on unmount.
 */
function onPaymentShortcut() {
    startPayment();
}

function onHandoutShortcut() {
    askBulkHandout();
}

function onConfirmShortcut(event) {
    if (! confirm.value) {
        return;
    }

    // Marks the Enter as consumed, so it cannot also activate the button that
    // regains focus behind the dialog.
    event.preventDefault();
    runConfirm();
}

onMounted(() => {
    window.addEventListener('pos-shortcut-payment', onPaymentShortcut);
    window.addEventListener('pos-shortcut-handout', onHandoutShortcut);
    window.addEventListener('pos-shortcut-confirm', onConfirmShortcut);
});

onUnmounted(() => {
    window.removeEventListener('pos-shortcut-payment', onPaymentShortcut);
    window.removeEventListener('pos-shortcut-handout', onHandoutShortcut);
    window.removeEventListener('pos-shortcut-confirm', onConfirmShortcut);
});

usePosKeyboard({
    onBackspace: () => router.visit(route('pos.attendee.lookup')),
    onNumpadDivide: () => startPayment(),
    onNumpadMultiply: () => askBulkHandout(),
});
</script>

<template>
    <div class="w-full flex-1 flex flex-col gap-2">
        <ConfirmModal
            :show="confirm !== null"
            :title="confirm?.title || ''"
            :message="confirm?.message || ''"
            :accept-label="confirm?.acceptLabel || 'Confirm'"
            :accept-severity="confirm?.acceptSeverity || null"
            @confirm="runConfirm()"
            @cancel="confirm = null"
        />

        <BadgeActionSheet
            :show="sheetBadge !== null"
            :badge="sheetBadge"
            @close="closeSheet()"
            @print="fromSheet(askPrint)"
            @handout="fromSheet(askHandout)"
            @undo="fromSheet(askUndo)"
            @pay="fromSheet((badge) => startPayment([badge.id]))"
        />

        <AttendeeDetailsSheet
            :show="showDetails"
            :attendee="attendee"
            :fursuits="fursuits"
            :transactions="transactions"
            :checkouts="checkouts"
            @close="showDetails = false"
        />

        <!-- Who is at the desk, and what they owe -->
        <div class="pos-card flex items-center justify-between gap-4 flex-wrap">
            <div class="flex flex-col">
                <h1 class="text-2xl font-bold leading-tight">{{ attendee.name }}</h1>
                <span class="pos-num text-sm text-pos-muted">
                    Reg #{{ eventUser?.attendee_id || 'N/A' }} · {{ currentEvent.name }}
                </span>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right">
                    <span class="pos-label block">Open balance</span>
                    <span class="pos-num text-2xl font-bold" :class="amountDue > 0 ? 'text-pos-bad' : ''">
                        {{ formatEuroFromCents(amountDue) }}
                    </span>
                </div>
                <Link :href="route('pos.dashboard')" class="pos-btn">Dashboard</Link>
            </div>
        </div>

        <!-- The work: one badge, one button -->
        <div class="pos-card__head px-1 mb-0">
            <h2 class="pos-label">
                Badges — {{ openCount }} open of {{ badges.length }}
                <span v-if="olderBadges.length" class="text-pos-warn">
                    · {{ olderBadges.length }} from earlier events
                </span>
            </h2>
            <button
                v-if="hasSelection"
                type="button"
                class="pos-btn pos-btn--sm"
                @click="selectedIds = []"
            >
                Clear selection ({{ selectedIds.length }})
            </button>
        </div>

        <div v-if="badges.length || olderBadges.length" class="pos-block pos-block--rows">
            <BadgeCard
                v-for="badge in badges"
                :key="badge.id"
                :badge="badge"
                :selected="isSelected(badge)"
                @toggle="toggleSelect"
                @act="runBadgeAction"
                @more="openSheet"
            />

            <!--
                Unclaimed badges from earlier conventions sit in the same list,
                because a queue is a queue: staff would otherwise scroll past the
                current event and miss them. They stay out of the bulk actions.
            -->
            <BadgeCard
                v-for="entry in olderBadges"
                :key="`past-${entry.badge.id}`"
                :badge="entry.badge"
                :event-label="entry.eventName"
                :selectable="false"
                @act="runBadgeAction"
                @more="openSheet"
            />
        </div>
        <div v-else class="pos-card text-center text-pos-muted py-8">
            No badges for this event.
        </div>

        <!-- Commit bar: the two money moves stay under the thumbs -->
        <div class="pos-commitbar grid-cols-2 md:grid-cols-4">
            <button
                type="button"
                class="pos-btn pos-btn--commit"
                :class="payTargets.length ? 'pos-btn--primary' : ''"
                :disabled="payTargets.length === 0"
                @click="startPayment()"
            >
                Pay {{ formatEuroFromCents(payTotal) }}
                <span class="pos-kcap">/</span>
            </button>
            <button
                type="button"
                class="pos-btn pos-btn--commit"
                :class="handoutTargets.length ? 'pos-btn--primary' : ''"
                :disabled="handoutTargets.length === 0"
                @click="askBulkHandout()"
            >
                Hand out {{ hasSelection ? 'selected' : 'all' }} ({{ handoutTargets.length }})
                <span class="pos-kcap">*</span>
            </button>
            <button type="button" class="pos-btn pos-btn--commit" @click="showDetails = true">
                <i class="pi pi-list"></i> Details
            </button>
            <Link :href="route('pos.attendee.lookup')" class="pos-btn pos-btn--commit">
                Next attendee <span class="pos-kcap">⌫</span>
            </Link>
        </div>
    </div>
</template>
