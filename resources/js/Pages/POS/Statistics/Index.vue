<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';
import POSLayout from '@/Layouts/POSLayout.vue';
import { formatEuroFromCents } from '@/helpers.js';

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    today: Object,
    totals: Object,
    fulfillment: Object,
    printing: Object,
    daily: Array,
    currentEvent: Object,
    generatedAt: String,
});

// Server caches for 60s, so refreshing more often than that only costs requests.
let refreshTimer = null;

onMounted(() => {
    refreshTimer = setInterval(() => {
        router.reload({ preserveState: true, preserveScroll: true });
    }, 60000);
});

onUnmounted(() => clearInterval(refreshTimer));

const todayTiles = computed(() => [
    { label: 'Taken today', value: formatEuroFromCents(props.today?.money_total ?? 0), sub: `${props.today?.checkouts ?? 0} checkouts`, primary: true },
    { label: 'Cash today', value: formatEuroFromCents(props.today?.money_cash ?? 0) },
    { label: 'Card today', value: formatEuroFromCents(props.today?.money_card ?? 0) },
    { label: 'Handed out today', value: props.today?.badges_handed_out ?? 0 },
    { label: 'Printed today', value: props.today?.badges_printed ?? 0 },
    { label: 'Ordered today', value: props.today?.badges_ordered ?? 0 },
]);

const fulfillmentRows = computed(() => Object.values(props.fulfillment ?? {}));

// Queue waits run to hours when the agent is down, and "8700s" is not a number
// anyone can read at a glance.
function formatDuration(seconds) {
    if (seconds === null || seconds === undefined) {
        return 'no prints today';
    }
    if (seconds < 60) {
        return `${seconds}s`;
    }
    if (seconds < 3600) {
        return `${Math.round(seconds / 60)}m`;
    }

    return `${Math.floor(seconds / 3600)}h ${Math.round((seconds % 3600) / 60)}m`;
}

const printRows = computed(() => [
    { label: 'Waiting', value: props.printing?.pending ?? 0 },
    { label: 'In progress', value: props.printing?.active ?? 0 },
    { label: 'Failed', value: props.printing?.failed ?? 0, bad: (props.printing?.failed ?? 0) > 0 },
    { label: 'Printed today', value: props.printing?.printed_today ?? 0 },
    { label: 'Average wait to print', value: formatDuration(props.printing?.average_seconds) },
    { label: 'Badge jobs / receipts', value: `${props.printing?.badge_jobs ?? 0} / ${props.printing?.receipt_jobs ?? 0}` },
]);

const maxDailyMoney = computed(
    () => Math.max(1, ...(props.daily ?? []).map((day) => day.money ?? 0))
);

const generatedTime = computed(() => {
    if (! props.generatedAt) {
        return '';
    }

    return new Date(props.generatedAt).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
});
</script>

<template>
    <Head>
        <title>POS - Statistics</title>
    </Head>

    <div class="w-full flex-1 flex flex-col gap-2 max-w-[1100px] mx-auto">
        <div class="pos-card flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold leading-tight">Statistics</h1>
                <span class="text-sm text-pos-muted">
                    {{ currentEvent?.name || 'No active event' }} · counted at {{ generatedTime }}
                </span>
            </div>
            <Link :href="route('pos.dashboard')" class="pos-btn">
                Dashboard <span class="pos-kcap">−</span>
            </Link>
        </div>

        <!-- Today: what this desk has done since midnight -->
        <div class="pos-block pos-block--cols">
            <div
                v-for="tile in todayTiles"
                :key="tile.label"
                class="pos-stat"
                :class="tile.primary ? 'pos-stat--primary' : ''"
            >
                <span class="pos-stat__k">{{ tile.label }}</span>
                <span class="pos-stat__v">{{ tile.value }}</span>
                <span v-if="tile.sub" class="pos-stat__sub">{{ tile.sub }}</span>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
            <!-- Badge queue: where every badge of this event stands -->
            <section class="flex flex-col gap-2">
                <h2 class="pos-label px-1">Badges — {{ totals?.badges ?? 0 }} this event</h2>
                <div class="pos-block pos-block--rows">
                    <div v-for="row in fulfillmentRows" :key="row.label" class="pos-row">
                        <span class="pos-row__body">
                            <span class="pos-row__title">{{ row.label }}</span>
                            <span class="pos-meter mt-2">
                                <span class="pos-meter__fill" :style="{ width: `${row.percent}%` }"></span>
                            </span>
                        </span>
                        <span class="pos-num text-lg font-bold w-16 text-right">{{ row.count }}</span>
                        <span class="pos-num text-xs text-pos-muted w-10 text-right">{{ row.percent }}%</span>
                    </div>
                </div>
            </section>

            <!-- Printing: the queue behind the desk -->
            <section class="flex flex-col gap-2">
                <h2 class="pos-label px-1">Printing</h2>
                <div class="pos-block pos-block--rows">
                    <div v-for="row in printRows" :key="row.label" class="pos-row">
                        <span class="pos-row__body">
                            <span class="pos-row__title">{{ row.label }}</span>
                        </span>
                        <span
                            class="pos-num text-lg font-bold"
                            :class="row.bad ? 'text-pos-bad' : ''"
                        >{{ row.value }}</span>
                    </div>
                </div>
            </section>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
            <!-- Money for the whole event -->
            <section class="flex flex-col gap-2">
                <h2 class="pos-label px-1">Money · whole event</h2>
                <div class="pos-block pos-block--rows">
                    <div class="pos-row">
                        <span class="pos-row__body">
                            <span class="pos-row__title">Taken in total</span>
                            <span class="pos-row__sub">{{ totals?.checkouts ?? 0 }} finished checkouts</span>
                        </span>
                        <span class="pos-num text-lg font-bold">{{ formatEuroFromCents(totals?.money_total ?? 0) }}</span>
                    </div>
                    <div class="pos-row">
                        <span class="pos-row__body"><span class="pos-row__title">Cash</span></span>
                        <span class="pos-num text-lg font-bold">{{ formatEuroFromCents(totals?.money_cash ?? 0) }}</span>
                    </div>
                    <div class="pos-row">
                        <span class="pos-row__body"><span class="pos-row__title">Card</span></span>
                        <span class="pos-num text-lg font-bold">{{ formatEuroFromCents(totals?.money_card ?? 0) }}</span>
                    </div>
                    <div class="pos-row" :class="(totals?.badges_unpaid ?? 0) > 0 ? 'pos-row--bad' : ''">
                        <span class="pos-row__body">
                            <span class="pos-row__title">Still unpaid</span>
                            <span class="pos-row__sub">{{ totals?.badges_unpaid ?? 0 }} badges not yet paid for</span>
                        </span>
                        <span class="pos-num text-lg font-bold">{{ formatEuroFromCents(totals?.unpaid_value ?? 0) }}</span>
                    </div>
                </div>
            </section>

            <!-- Counts that describe the event rather than the day -->
            <section class="flex flex-col gap-2">
                <h2 class="pos-label px-1">Event</h2>
                <div class="pos-block pos-block--rows">
                    <div class="pos-row">
                        <span class="pos-row__body"><span class="pos-row__title">Attendees registered</span></span>
                        <span class="pos-num text-lg font-bold">{{ totals?.participants ?? 0 }}</span>
                    </div>
                    <div class="pos-row">
                        <span class="pos-row__body"><span class="pos-row__title">Badges ordered</span></span>
                        <span class="pos-num text-lg font-bold">{{ totals?.badges ?? 0 }}</span>
                    </div>
                    <div class="pos-row">
                        <span class="pos-row__body"><span class="pos-row__title">Double sided</span></span>
                        <span class="pos-num text-lg font-bold">{{ totals?.double_sided ?? 0 }}</span>
                    </div>
                    <div class="pos-row">
                        <span class="pos-row__body"><span class="pos-row__title">Spare copies</span></span>
                        <span class="pos-num text-lg font-bold">{{ totals?.extra_copies ?? 0 }}</span>
                    </div>
                </div>
            </section>
        </div>

        <!-- Per day of the convention. Bars are relative to the busiest day. -->
        <section v-if="daily?.length" class="flex flex-col gap-2">
            <h2 class="pos-label px-1">By event day</h2>
            <div class="pos-block pos-block--rows">
                <div v-for="day in daily" :key="day.date" class="pos-row">
                    <span class="w-16 shrink-0">
                        <span class="pos-row__title">{{ day.day_name }}</span>
                        <span class="pos-row__sub pos-num">{{ day.date.slice(8) }}.{{ day.date.slice(5, 7) }}.</span>
                    </span>
                    <span class="pos-row__body">
                        <span class="pos-meter">
                            <span class="pos-meter__fill" :style="{ width: `${Math.round((day.money / maxDailyMoney) * 100)}%` }"></span>
                        </span>
                    </span>
                    <span class="pos-num text-sm text-pos-muted w-24 text-right">{{ day.badges_ordered }} ordered</span>
                    <span class="pos-num text-sm text-pos-muted w-28 text-right">{{ day.badges_handed_out }} handed out</span>
                    <span class="pos-num font-bold w-28 text-right">{{ formatEuroFromCents(day.money) }}</span>
                </div>
            </div>
        </section>
    </div>
</template>
