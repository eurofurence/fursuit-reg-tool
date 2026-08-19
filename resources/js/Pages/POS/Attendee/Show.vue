<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useForm } from 'laravel-precognition-vue-inertia';
import dayjs from 'dayjs';

import POSLayout from '@/Layouts/POSLayout.vue';
import ConfirmModal from '@/Components/POS/ConfirmModal.vue';
import BadgeCard from '@/Components/POS/Attendee/BadgeCard.vue';
import AttendeeDetailsSheet from '@/Components/POS/Attendee/AttendeeDetailsSheet.vue';
import BadgeEditModal from '@/Components/POS/BadgeEditModal.vue';
import PriceOverrideModal from '@/Components/POS/PriceOverrideModal.vue';
import { badgeAction, isHandoutable, isPayable } from '@/Components/POS/Attendee/badgeAction.js';
import { usePosKeyboard } from '@/composables/usePosKeyboard';
import { formatEuroFromCents } from '@/helpers.js';

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    badges: Array,
    fursuits: Array,
    checkouts: Array,
    attendee: Object,
    amountDue: Number,
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

// With a selection the bar prices exactly what is selected; without one it
// shows the total of every unpaid badge, which is the number the attendee was told.
const payTotal = computed(() =>
    hasSelection.value ? payTargets.value.reduce((sum, badge) => sum + (badge.total ?? 0), 0) : props.amountDue
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
 * Printing is the only step that asks: it burns a card and ties up the printer.
 * Handing out, undoing a handout and taking payment are all either reversible
 * from the same row or lead to a screen that can be cancelled, and the desk
 * works a queue — every extra tap is queue time.
 */
const confirm = ref(null);

function badgeLabel(badge) {
    return `${badge.fursuit?.name || 'this badge'} (${badge.custom_id || badge.id})`;
}

/*
 * Badges this screen has pressed Print on, remembered client-side.
 *
 * Queueing only opens the batch; the badge does not reach Processing until the
 * worker picks up PrepareBadgePrintBatchJob a moment later. For those seconds
 * the server still says "pending", so the row would go back to reading "Print"
 * right after the clerk pressed it - they look at the printer, see nothing yet,
 * look back at a button that says Print, and press it again. The set bridges the
 * gap until the server agrees, which is why the print submit preserves state.
 */
const justQueued = ref(new Set());

function isQueued(badge) {
    return justQueued.value.has(badge.id);
}

/*
 * A first print asks once. A second one asks twice.
 *
 * The desk's expensive mistake here is a duplicate card: blank stock is finite,
 * the printer is the bottleneck at the door, and a card that is merely still in
 * the queue looks exactly like one that never printed. So a re-print states what
 * the badge is actually doing, makes the clerk say which of the two it is, and
 * only then offers the button that burns another card. Enter does not reach
 * either of those two dialogs - a duplicate has to be deliberate.
 */
function askPrint(badge) {
    const printing = badge.status_fulfillment === 'processing' || isQueued(badge);

    if (! printing && ! badge.printed_at) {
        confirm.value = {
            kind: 'print',
            badge,
            title: 'Print badge',
            message: `Queue a print job for ${badgeLabel(badge)}?`,
            acceptLabel: 'Print',
        };

        return;
    }

    confirm.value = {
        kind: 'reprint-check',
        badge,
        requireClick: true,
        title: printing ? 'This badge is printing now' : 'This badge was already printed',
        message: printing
            ? `A print job for ${badgeLabel(badge)} is queued or on the printer right now. Check the printer and the crate before making a second card.`
            : `${badgeLabel(badge)} was printed at ${dayjs(badge.printed_at).format('DD.MM. HH:mm')}. Check the crate before making a second card.`,
        acceptLabel: printing ? 'It has not come out' : 'It is missing or damaged',
        acceptSeverity: 'danger',
    };
}

// Instant: handing out is the move the desk makes hundreds of times an hour,
// and the same row can undo it.
function handoutNow(badge) {
    useForm('POST', route('pos.badges.handout', { badge: badge.id }), {})
        .submit({ preserveScroll: true });
}

function undoNow(badge) {
    useForm('POST', route('pos.badges.handout.undo', { badge: badge.id }), {})
        .submit({ preserveScroll: true });
}

function handoutAll() {
    if (handoutTargets.value.length === 0) {
        return;
    }

    useForm('POST', route('pos.badges.handout.bulk'), {
        badge_ids: handoutTargets.value.map((badge) => badge.id),
    }).submit({ preserveScroll: true });

    selectedIds.value = [];
}

function runConfirm() {
    const pending = confirm.value;
    if (! pending) {
        return;
    }

    // Cleared first: a second confirm arriving before the dialog closes would
    // otherwise fire the same print twice.
    confirm.value = null;

    // The state has been acknowledged; now the actual "burn another card" ask.
    if (pending.kind === 'reprint-check') {
        confirm.value = {
            kind: 'print',
            badge: pending.badge,
            requireClick: true,
            title: 'Print another copy',
            message: `This sends a second card for ${badgeLabel(pending.badge)} to the printer. The first one stays valid if it turns up.`,
            acceptLabel: 'Print another copy',
            acceptSeverity: 'danger',
        };

        return;
    }

    useForm('POST', route('pos.badges.print', { badge: pending.badge.id }), {})
        .submit({
            preserveScroll: true,
            // Keeps `justQueued` across the redirect back, which is the only
            // thing marking this badge as sent until the worker moves it.
            preserveState: true,
            onSuccess: () => justQueued.value.add(pending.badge.id),
        });
}

/* --- Money ---------------------------------------------------------------- */

function startPayment(badgeIds = null) {
    const ids = badgeIds ?? payTargets.value.map((badge) => badge.id);

    router.post(route('pos.checkout.store', {
        user_id: props.attendee.id,
        badge_ids: ids,
    }));
}

/* --- Editing --------------------------------------------------------------
 * Deliberately not a button on every card: correcting a badge is the rare move,
 * and a third control in the row would sit next to Print and Hand out all shift
 * collecting mis-taps. Pick exactly one badge and the commit bar offers it.
 */
const editing = ref(null);
const overriding = ref(null);

const editTarget = computed(() => {
    if (selectedIds.value.length !== 1) {
        return null;
    }

    return props.badges.find((badge) => badge.id === selectedIds.value[0]) ?? null;
});

// The override dialog speaks in generic lines so the payment screen can reuse it.
const overrideItems = computed(() => (overriding.value
    ? [{
        id: overriding.value.id,
        label: overriding.value.fursuit?.name || 'Fursuit badge',
        sublabel: overriding.value.custom_id || `#${overriding.value.id}`,
        total: overriding.value.total ?? 0,
    }]
    : []));

function startOverride(badge) {
    editing.value = null;
    overriding.value = badge;
}

/* --- Leaving with work left -----------------------------------------------
 * The desk's worst failure is quiet: the clerk closes the attendee, the next
 * one steps up, and a printed card stays in the crate with nobody looking for
 * it again until the attendee comes back asking. Every way off this page - the
 * back arrow in the system bar, Dashboard, Next attendee, the Backspace
 * shortcut and the browser's own back button - goes through the same check.
 *
 * Only actual departures are stopped. Paying and handing out post to the server
 * and come back here, so non-GET visits pass straight through, as does the
 * checkout screen: starting a payment is doing the work, not abandoning it.
 */
const pendingHandout = computed(() => props.badges.filter(isHandoutable));
const pendingPayment = computed(() => props.badges.filter(isPayable));
const hasOpenWork = computed(() => pendingHandout.value.length > 0 || pendingPayment.value.length > 0);

const leaveTarget = ref(null);
let leaveConfirmed = false;

const leaveMessage = computed(() => {
    const parts = [];

    if (pendingHandout.value.length) {
        parts.push(`${pendingHandout.value.length} badge${pendingHandout.value.length === 1 ? '' : 's'} awaiting hand out`);
    }
    if (pendingPayment.value.length) {
        parts.push(`${pendingPayment.value.length} unpaid`);
    }

    return `Did you hand out all the badges? ${props.attendee.name} still has ${parts.join(' and ')}.`;
});

function isLeaving(visit) {
    if (visit.method && String(visit.method).toLowerCase() !== 'get') {
        return false;
    }

    const path = typeof visit.url === 'string'
        ? new URL(visit.url, window.location.origin).pathname
        : visit.url?.pathname ?? '';

    // Reloading this same screen, or stepping into the checkout, is the work.
    return path !== window.location.pathname && ! path.startsWith('/pos/checkout');
}

function confirmLeave() {
    const target = leaveTarget.value;
    leaveTarget.value = null;
    leaveConfirmed = true;

    if (target) {
        router.visit(target);
    }
}

let stopLeaveGuard = null;

/* --- Sheets --------------------------------------------------------------- */

const showDetails = ref(false);

function runBadgeAction({ badge, action }) {
    if (action === 'pay') {
        startPayment([badge.id]);
    } else if (action === 'print') {
        askPrint(badge);
    } else if (action === 'handout') {
        handoutNow(badge);
    }
}

/* --- Keyboard -------------------------------------------------------------
 * Handlers are named so they can be removed again on unmount.
 */
function onPaymentShortcut() {
    startPayment();
}

function onHandoutShortcut() {
    handoutAll();
}

function onConfirmShortcut(event) {
    if (leaveTarget.value) {
        event.preventDefault();
        confirmLeave();

        return;
    }

    if (! confirm.value) {
        return;
    }

    // Marks the Enter as consumed, so it cannot also activate the button that
    // regains focus behind the dialog.
    event.preventDefault();

    // Both halves of the re-print ask are click-only. Enter is how the desk
    // clears a normal print confirm without leaving the keypad, and a clerk
    // tapping through at speed would otherwise blow past both warnings and
    // burn a card before reading either.
    if (confirm.value.requireClick) {
        return;
    }

    runConfirm();
}

// The browser's own back button never reaches Inertia's `before` event, so it
// gets the same treatment through history: a duplicate entry sits on top of this
// page, and the press that pops it asks instead of navigating. With nothing left
// open the press is handed straight on to the real previous page, so a finished
// attendee still costs one tap of Back.
let sentinelPushed = false;

function onPopState() {
    if (! hasOpenWork.value || leaveConfirmed) {
        if (sentinelPushed) {
            sentinelPushed = false;
            window.history.back();
        }

        return;
    }

    window.history.pushState(window.history.state, '', window.location.href);
    leaveTarget.value = route('pos.dashboard');
}

onMounted(() => {
    window.addEventListener('pos-shortcut-payment', onPaymentShortcut);
    window.addEventListener('pos-shortcut-handout', onHandoutShortcut);
    window.addEventListener('pos-shortcut-confirm', onConfirmShortcut);

    stopLeaveGuard = router.on('before', (event) => {
        if (! hasOpenWork.value || leaveConfirmed || ! isLeaving(event.detail.visit)) {
            return;
        }

        leaveTarget.value = event.detail.visit.url.toString();

        return false;
    });

    window.history.pushState(window.history.state, '', window.location.href);
    sentinelPushed = true;
    window.addEventListener('popstate', onPopState);
});

onUnmounted(() => {
    window.removeEventListener('pos-shortcut-payment', onPaymentShortcut);
    window.removeEventListener('pos-shortcut-handout', onHandoutShortcut);
    window.removeEventListener('pos-shortcut-confirm', onConfirmShortcut);
    window.removeEventListener('popstate', onPopState);
    stopLeaveGuard?.();
});

usePosKeyboard({
    onBackspace: () => router.visit(route('pos.dashboard')),
    onNumpadDivide: () => startPayment(),
    onNumpadMultiply: () => handoutAll(),
});
</script>

<template>
    <!--
        Capped and centred: on a 1920px desk screen a full-bleed row put the
        Hand out button a metre away from the name it belongs to, which is a
        long way to drag your eye between reading a badge and acting on it.
    -->
    <div class="w-full flex-1 flex flex-col gap-2 max-w-[1100px] mx-auto">
        <ConfirmModal
            :show="confirm !== null"
            :title="confirm?.title || ''"
            :message="confirm?.message || ''"
            :accept-label="confirm?.acceptLabel || 'Confirm'"
            :accept-severity="confirm?.acceptSeverity || null"
            :show-enter-hint="! confirm?.requireClick"
            @confirm="runConfirm()"
            @cancel="confirm = null"
        />

        <ConfirmModal
            :show="leaveTarget !== null"
            title="STOP"
            :message="leaveMessage"
            accept-label="Leave anyway"
            accept-severity="danger"
            reject-label="Stay here"
            @confirm="confirmLeave()"
            @cancel="leaveTarget = null"
        />

        <AttendeeDetailsSheet
            :show="showDetails"
            :attendee="attendee"
            :fursuits="fursuits"
            :checkouts="checkouts"
            @close="showDetails = false"
        />

        <BadgeEditModal
            :show="editing !== null"
            :badge="editing"
            @close="editing = null"
            @override-price="startOverride"
        />

        <PriceOverrideModal
            :show="overriding !== null"
            :items="overrideItems"
            @close="overriding = null"
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
                :queued="isQueued(badge)"
                @toggle="toggleSelect"
                @act="runBadgeAction"
                @print="askPrint"
                @undo="undoNow"
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
                :queued="isQueued(entry.badge)"
                @act="runBadgeAction"
                @print="askPrint"
                @undo="undoNow"
            />
        </div>
        <div v-else class="pos-card text-center text-pos-muted py-8">
            No badges for this event.
        </div>

        <!--
            Commit bar: the two money moves stay under the thumbs. Edit keeps a
            fixed slot rather than appearing and disappearing with the selection,
            because a bar that reflows is a bar whose buttons get pressed by
            position and hit the wrong one.
        -->
        <div class="pos-commitbar grid-cols-2 md:grid-cols-5">
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
                @click="handoutAll()"
            >
                Hand out {{ hasSelection ? 'selected' : 'all' }} ({{ handoutTargets.length }})
                <span class="pos-kcap">*</span>
            </button>
            <button
                type="button"
                class="pos-btn pos-btn--commit"
                :disabled="editTarget === null"
                :title="editTarget === null ? 'Select exactly one badge to edit it' : ''"
                @click="editing = editTarget"
            >
                Edit badge
            </button>
            <button type="button" class="pos-btn pos-btn--commit" @click="showDetails = true">
                <i class="pi pi-list"></i> Details
            </button>
            <Link :href="route('pos.dashboard')" class="pos-btn pos-btn--commit">
                Next attendee <span class="pos-kcap">⌫</span>
            </Link>
        </div>
    </div>
</template>
