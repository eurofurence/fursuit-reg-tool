<script setup>
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import POSLayout from "@/Layouts/POSLayout.vue";
import { usePosKeyboard } from "@/composables/usePosKeyboard";
import Dialog from "primevue/dialog";
import { posDialogPt } from "@/Components/POS/posDialog.js";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    stats: Object,
    event: Object,
    badgeRange: Object,
    printNotifications: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const machine = computed(() => page.props.auth.machine);

/* --- Attendee lookup (inline, no extra navigation) ------------------------ */

const maxAttendeeIdLength = 5;
const attendeeIdInput = ref(null);

const form = useForm({
    attendeeId: '',
});

const lookupError = computed(() => form.errors.attendeeId || page.props.errors?.attendeeId);

function focusInput() {
    nextTick(() => attendeeIdInput.value?.focus());
}

function submit() {
    if (! form.attendeeId || form.processing) {
        return;
    }
    form.post(route('pos.attendee.lookup.submit'), {
        preserveScroll: true,
        onError: () => {
            form.attendeeId = '';
            focusInput();
        },
    });
}

function keyPress(key) {
    if (key === 'delete') {
        form.attendeeId = form.attendeeId.slice(0, -1);
    } else if (key === 'enter') {
        submit();
    } else if (form.attendeeId.length < maxAttendeeIdLength) {
        form.attendeeId += key;
    }
    focusInput();
}

// Numpad "/" is the global "search attendee" shortcut. On the dashboard the
// search box is already here, so clear it and focus instead of navigating away.
usePosKeyboard({
    onNumpadDivide: () => {
        form.attendeeId = '';
        focusInput();
    },
});

// Scanners and the numpad both emit stray non-digits ("/", "*", Enter chars).
watch(() => form.attendeeId, (value) => {
    const digits = (value || '').replace(/\D/g, '').slice(0, maxAttendeeIdLength);
    if (digits !== value) {
        form.attendeeId = digits;
    }
});

// Keep the counters honest without the staff reloading the page.
let statsTimer = null;

onMounted(() => {
    focusInput();
    // Every 10s rather than 30: this is also how a clerk learns their card came
    // out, and half a minute is a long time to stand at the counter saying
    // nothing. Two small props, no page state touched.
    statsTimer = setInterval(() => {
        router.reload({ only: ['stats', 'printNotifications'], preserveState: true, preserveScroll: true });
    }, 10000);
});

onUnmounted(() => clearInterval(statsTimer));

/* --- Stats & navigation --------------------------------------------------- */

const printQueueTotal = computed(
    () => (props.stats?.pending_print ?? 0) + (props.stats?.active_print ?? 0)
);

/* --- Badge range (which crate this desk holds) ---------------------------- */

const showRangeDialog = ref(false);

const rangeForm = useForm({
    badge_range_min: props.badgeRange?.min ?? null,
    badge_range_max: props.badgeRange?.max ?? null,
});

const hasBadgeRange = computed(
    () => props.badgeRange?.min !== null && props.badgeRange?.min !== undefined
        || props.badgeRange?.max !== null && props.badgeRange?.max !== undefined
);

const badgeRangeLabel = computed(() => {
    const min = props.badgeRange?.min;
    const max = props.badgeRange?.max;

    if (min !== null && min !== undefined && max !== null && max !== undefined) {
        return `attendee ${min}–${max}`;
    }
    if (min !== null && min !== undefined) {
        return `attendee ${min} and up`;
    }
    if (max !== null && max !== undefined) {
        return `attendee up to ${max}`;
    }

    return 'all badges · tap to limit';
});

function openRangeDialog() {
    rangeForm.clearErrors();
    rangeForm.badge_range_min = props.badgeRange?.min ?? null;
    rangeForm.badge_range_max = props.badgeRange?.max ?? null;
    showRangeDialog.value = true;
}

// Empty stays null, not 0: null on both ends is what makes the desk count
// every badge again, and "0" is a legitimate lower bound.
function normalizeRange(value) {
    if (value === '' || value === null || value === undefined) {
        return null;
    }

    return parseInt(value, 10);
}

function saveRange() {
    rangeForm
        .transform((data) => ({
            badge_range_min: normalizeRange(data.badge_range_min),
            badge_range_max: normalizeRange(data.badge_range_max),
        }))
        .put(route('pos.machine.badge-range', { machine: machine.value?.id }), {
            preserveScroll: true,
            onSuccess: () => {
                showRangeDialog.value = false;
                router.reload({ only: ['stats', 'badgeRange'], preserveState: true, preserveScroll: true });
            },
        });
}

function clearRange() {
    rangeForm.badge_range_min = null;
    rangeForm.badge_range_max = null;
    saveRange();
}

const statTiles = computed(() => [
    {
        label: 'Left to pick up',
        value: props.stats?.ready_for_pickup ?? 0,
        sub: badgeRangeLabel.value,
        primary: true,
        action: openRangeDialog,
    },
    {
        label: 'Handed out by you',
        value: props.stats?.my_picked_up_total ?? 0,
        sub: `${props.stats?.my_picked_up_today ?? 0} of them today`,
    },
    {
        label: 'Handed out in total',
        value: props.stats?.picked_up_total ?? 0,
        sub: `you did ${props.stats?.my_share_percent ?? 0}% · ${props.stats?.picked_up_today ?? 0} today`,
        progress: props.stats?.my_share_percent ?? 0,
    },
]);

const actions = computed(() => [
    {
        label: 'Badge Management',
        subtitle: 'View & print badges',
        route: route('pos.badges.index'),
        icon: 'pi pi-id-card',
        key: 'F3',
    },
    {
        label: 'Print Queue',
        subtitle: printQueueTotal.value
            ? `${props.stats?.pending_print ?? 0} pending · ${props.stats?.active_print ?? 0} active`
            : 'Nothing queued',
        route: route('pos.print-queue.index'),
        icon: 'pi pi-print',
        key: 'F4',
        count: printQueueTotal.value || null,
    },
    {
        label: 'My Print Jobs',
        subtitle: props.stats?.my_print_batches_running
            ? `${props.stats.my_print_batches_running} of yours still printing`
            : 'Runs you started',
        route: route('pos.my-prints.index'),
        icon: 'pi pi-history',
        key: 'F7',
        count: props.printNotifications.length || null,
    },
    {
        label: 'Badge Verification',
        subtitle: 'Check a printed box off',
        route: route('pos.verification.index'),
        icon: 'pi pi-check-square',
        key: 'F8',
    },
    {
        label: 'Statistics',
        subtitle: 'Reports & totals',
        route: route('pos.statistics'),
        icon: 'pi pi-chart-bar',
        key: 'F6',
    },
]);

/* --- Print notifications -------------------------------------------------- */

// Where a notification takes the clerk. One card goes straight to the attendee
// waiting for it; a run of several has no single attendee, so it opens the list.
function notificationTarget(notification) {
    const single = notification.badges.length === 1 ? notification.badges[0] : null;

    return single?.attendee_url ?? route('pos.my-prints.index');
}

// Seen is dismissed. The clerk is on their way to hand the card over, and
// making them come back to clear the row is one tap of pure ceremony.
function openNotification(notification) {
    const target = notificationTarget(notification);

    router.post(route('pos.my-prints.dismiss', { printBatch: notification.id }), {}, {
        preserveScroll: true,
        onFinish: () => router.visit(target),
    });
}

function dismissNotification(notification) {
    router.post(route('pos.my-prints.dismiss', { printBatch: notification.id }), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => router.reload({ only: ['stats', 'printNotifications'], preserveState: true, preserveScroll: true }),
    });
}

function dismissAllNotifications() {
    router.post(route('pos.my-prints.dismiss-all'), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => router.reload({ only: ['stats', 'printNotifications'], preserveState: true, preserveScroll: true }),
    });
}

function notificationSub(notification) {
    const single = notification.badges.length === 1 ? notification.badges[0] : null;

    if (single) {
        const who = single.attendee_id ? `#${single.attendee_id}` : 'unknown attendee';

        return `${single.custom_id ?? notification.name} · ${who}${single.attendee_name ? ` · ${single.attendee_name}` : ''}`;
    }

    return `${notification.name} · ${notification.printed_count}/${notification.total_jobs} printed`;
}
</script>

<template>
    <Head>
        <title>POS - Dashboard</title>
    </Head>

    <div class="w-full flex-1 flex flex-col gap-2">
        <!-- Stat strip: one ruled block, cells share their borders -->
        <div class="pos-block pos-block--cols">
            <component
                v-for="tile in statTiles"
                :is="tile.action ? 'button' : 'div'"
                :key="tile.label"
                :type="tile.action ? 'button' : null"
                class="pos-stat"
                :class="[tile.primary ? 'pos-stat--primary' : '', tile.action ? 'pos-stat--action text-left' : '']"
                @click="tile.action ? tile.action() : null"
            >
                <span class="pos-stat__k flex items-center gap-2">
                    {{ tile.label }}
                    <i v-if="tile.action" class="pi pi-sliders-h text-[0.7rem]"></i>
                </span>
                <span class="pos-stat__v">{{ tile.value }}</span>
                <span class="pos-stat__sub" :class="tile.action && hasBadgeRange ? 'font-semibold' : ''">{{ tile.sub }}</span>
                <span v-if="tile.progress !== undefined" class="pos-meter" :aria-label="`${tile.progress}% handed out`">
                    <span class="pos-meter__fill" :style="{ width: `${Math.min(100, tile.progress)}%` }"></span>
                </span>
            </component>
        </div>

        <!-- Which crate of badges this desk holds -->
        <Dialog
            v-model:visible="showRangeDialog"
            modal
            header="Badges at this desk"
            :style="{ width: '30rem' }"
            :pt="posDialogPt"
        >
            <p class="text-sm text-pos-muted mb-3">
                Count only badges whose attendee ID falls in this range. Leave both
                fields empty to count every badge of the event.
            </p>

            <div class="grid grid-cols-2 gap-2">
                <label class="flex flex-col gap-1">
                    <span class="pos-label">First ID</span>
                    <input
                        v-model="rangeForm.badge_range_min"
                        class="pos-field"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="0"
                    />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="pos-label">Last ID</span>
                    <input
                        v-model="rangeForm.badge_range_max"
                        class="pos-field"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="∞"
                    />
                </label>
            </div>

            <p v-if="rangeForm.errors.badge_range_min || rangeForm.errors.badge_range_max"
               class="mt-2 px-3 py-2 rounded-pos border border-pos-bad text-pos-bad text-sm font-semibold">
                {{ rangeForm.errors.badge_range_min || rangeForm.errors.badge_range_max }}
            </p>

            <template #footer>
                <button type="button" class="pos-btn" @click="showRangeDialog = false">Cancel</button>
                <button type="button" class="pos-btn" @click="clearRange()">Count all badges</button>
                <button
                    type="button"
                    class="pos-btn pos-btn--primary"
                    :disabled="rangeForm.processing"
                    @click="saveRange()"
                >
                    Save
                </button>
            </template>
        </Dialog>

        <!-- Work surface: lookup left, navigation right -->
        <div class="flex-1 grid grid-cols-1 lg:grid-cols-3 gap-2">
            <section class="lg:col-span-2 pos-card flex flex-col">
                <div class="pos-card__head">
                    <h1>Attendee Lookup</h1>
                    <div class="flex items-center gap-3 text-xs text-pos-muted">
                        <span><span class="pos-kcap mr-1">0-9</span>attendee</span>
                        <span><span class="pos-kcap mr-1">Enter</span>search</span>
                        <span><span class="pos-kcap mr-1">*</span>hand out</span>
                        <span><span class="pos-kcap mr-1">F1</span>all keys</span>
                    </div>
                </div>

                <p v-if="lookupError" class="mb-2 px-3 py-2 rounded-pos border border-pos-bad text-pos-bad text-sm font-semibold">
                    {{ lookupError }}
                </p>

                <form class="flex gap-2" @submit.prevent="submit">
                    <input
                        ref="attendeeIdInput"
                        v-model="form.attendeeId"
                        class="pos-field"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="Attendee ID"
                        :maxlength="maxAttendeeIdLength"
                    />
                    <button
                        type="submit"
                        class="pos-btn pos-btn--primary pos-btn--commit px-8"
                        :disabled="!form.attendeeId || form.processing"
                    >
                        Search <span class="pos-kcap">Enter</span>
                    </button>
                </form>

                <div class="flex-1 flex items-center justify-center mt-2">
                    <div class="pos-keypad w-full max-w-md h-full max-h-[24rem]">
                        <button v-for="n in ['7','8','9','4','5','6','1','2','3','0']" :key="n"
                                type="button" class="pos-key" @click="keyPress(n)">{{ n }}</button>
                        <button type="button" class="pos-key pos-key--wide" @click="keyPress('delete')">Delete</button>
                        <button type="button" class="pos-key pos-key--go" @click="keyPress('enter')">Search</button>
                    </div>
                </div>
            </section>

            <div class="flex flex-col gap-2 self-start w-full">
            <!-- Navigation: one ruled block, rows share their borders -->
            <nav class="pos-block pos-block--rows self-start w-full">
                <Link
                    v-for="action in actions"
                    :key="action.label"
                    :href="action.route"
                    class="pos-row"
                    :class="action.alert ? 'pos-row--bad' : ''"
                >
                    <span class="pos-row__glyph"><i :class="action.icon"></i></span>
                    <span class="pos-row__body">
                        <span class="pos-row__title">{{ action.label }}</span>
                        <span class="pos-row__sub" :class="action.alert ? 'text-pos-bad font-semibold' : ''">
                            {{ action.subtitle }}
                        </span>
                    </span>
                    <span v-if="action.count" class="pos-count" :class="action.alert ? 'pos-count--bad' : ''">
                        {{ action.count }}
                    </span>
                    <span class="pos-kcap">{{ action.key }}</span>
                </Link>
            </nav>

            <!-- Print runs this clerk started that have something to say -->
            <section v-if="printNotifications.length" class="pos-card">
                <div class="pos-card__head">
                    <h1>Your print jobs</h1>
                    <button type="button" class="pos-btn pos-btn--sm" @click="dismissAllNotifications()">
                        Clear all
                    </button>
                </div>

                <div class="pos-block pos-block--rows w-full">
                    <div
                        v-for="notification in printNotifications"
                        :key="notification.id"
                        class="flex items-stretch"
                    >
                        <button
                            type="button"
                            class="pos-row flex-1 text-left"
                            :class="notification.tone === 'bad' ? 'pos-row--bad' : 'pos-row--good'"
                            @click="openNotification(notification)"
                        >
                            <span class="pos-row__glyph">
                                <i :class="notification.tone === 'bad' ? 'pi pi-exclamation-triangle' : 'pi pi-check-circle'"></i>
                            </span>
                            <span class="pos-row__body">
                                <span class="pos-row__title">{{ notification.headline }}</span>
                                <span class="pos-row__sub" :class="notification.tone === 'bad' ? 'text-pos-bad font-semibold' : ''">
                                    {{ notificationSub(notification) }}
                                </span>
                                <span v-if="notification.pause_reason" class="pos-row__sub text-pos-bad">
                                    {{ notification.pause_reason }}
                                </span>
                            </span>
                        </button>
                        <button
                            type="button"
                            class="pos-row pos-row--tail px-4"
                            title="Dismiss"
                            aria-label="Dismiss notification"
                            @click="dismissNotification(notification)"
                        >
                            <i class="pi pi-times"></i>
                        </button>
                    </div>
                </div>
            </section>
            </div>
        </div>

    </div>
</template>
