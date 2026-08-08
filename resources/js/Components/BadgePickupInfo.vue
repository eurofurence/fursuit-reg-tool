<script setup>
import { computed } from 'vue';
import dayjs from 'dayjs';
import Card from '@/Components/UI/UiCard.vue';

/*
 * Pickup desk explainer for attendees.
 *
 * The desk splits its queue across six booths by attendee id, which is also the
 * prefix of every badge's custom_id ("1234-2" => attendee 1234). Attendees who
 * queue at the wrong booth have to be sent to another line, which is what makes
 * the day-1 rush slow, so the booth for *this* attendee is highlighted.
 */
const props = defineProps({
    // Attendee id (event_users.attendee_id). Null when unknown - the booth grid
    // is still shown, just without a highlight.
    attendeeId: { type: [String, Number], default: null },
    // Badge id such as "1234-2"; its prefix wins over attendeeId when present.
    customId: { type: String, default: null },
    // Optional one-liner about when this particular badge can be collected.
    timing: { type: String, default: null },
    // Desk opening hours as configured for the event: [{date, opens, closes, note}].
    // Empty until the desk team publishes any, and the block is dropped when it is -
    // an invented time sends someone to a closed hall.
    openingHours: { type: Array, default: () => [] },
    // Booth ranges as configured for the event: [{label, from, to}], `to` null on the
    // open-ended last booth. Falls back to FALLBACK_BOOTHS when the page does not pass any.
    booths: { type: Array, default: null },
});

/*
 * Only used when a page renders this component without booths, e.g. a page that
 * has no event to read them from. The live values come from the event and are
 * edited in the admin panel (Settings -> On-Site Desk); these mirror
 * App\Support\PickupBooths::DEFAULTS.
 */
const FALLBACK_BOOTHS = [
    { label: '0 – 999', from: 0, to: 999 },
    { label: '1000 – 1999', from: 1000, to: 1999 },
    { label: '2000 – 3499', from: 2000, to: 3499 },
    { label: '3500 – 5499', from: 3500, to: 5499 },
    { label: '5500 – 7499', from: 5500, to: 7499 },
    { label: '7500 and up', from: 7500, to: null },
];

const attendeeNumber = computed(() => {
    const raw = props.customId ? String(props.customId).split('-')[0] : props.attendeeId;
    if (raw === null || raw === undefined || raw === '') return null;

    const parsed = Number.parseInt(String(raw), 10);

    return Number.isNaN(parsed) ? null : parsed;
});

const booths = computed(() => {
    const configured = props.booths?.length ? props.booths : FALLBACK_BOOTHS;

    return configured.map((booth, index) => ({
        label: booth.label ?? (booth.to === null ? `${booth.from} and up` : `${booth.from} – ${booth.to}`),
        number: index + 1,
        isYours:
            attendeeNumber.value !== null &&
            attendeeNumber.value >= booth.from &&
            (booth.to === null || booth.to === undefined || attendeeNumber.value <= booth.to),
    }));
});

const yourBooth = computed(() => booths.value.find((booth) => booth.isYours) ?? null);

// Derived from the stored date, never stored beside it, so the weekday and the day it
// names cannot drift apart.
const dayLabel = (date) => dayjs(date).format('ddd, D MMM');
</script>

<template>
    <Card>
        <template #title>
            <div class="flex items-center gap-2">
                <i class="pi pi-map-marker text-primary-600"></i>
                <span>Where to pick up your badge</span>
            </div>
        </template>
        <template #content>
            <ul class="space-y-1 text-sm text-gray-600 list-disc pl-5">
                <li>At the badge desk in the Fursuit Lounge</li>
                <li>You do <strong>not</strong> need to bring your fursuit</li>
                <li v-if="timing">{{ timing }}</li>
            </ul>

            <div v-if="openingHours.length" class="mt-5 border-t border-gray-200 pt-4">
                <h3 class="font-semibold">Opening hours</h3>
                <ul class="mt-2 text-sm">
                    <li
                        v-for="(row, index) in openingHours"
                        :key="index"
                        class="flex flex-wrap items-baseline justify-between gap-x-4 py-1"
                    >
                        <span class="font-medium text-gray-800">{{ dayLabel(row.date) }}</span>
                        <span class="text-gray-600">{{ row.opens }} &ndash; {{ row.closes }}</span>
                        <span v-if="row.note" class="w-full text-xs text-gray-500">{{ row.note }}</span>
                    </li>
                </ul>
            </div>

            <div class="mt-5 border-t border-gray-200 pt-4">
                <h3 class="font-semibold">Six booths, one per number range</h3>
                <p class="mt-1 text-sm text-gray-600">
                    On the first convention day the desk runs six booths, each serving its own
                    range of attendee numbers, with its own queue. Check the sign at the booth
                    and line up at the one that matches your number.
                </p>

                <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <div
                        v-for="booth in booths"
                        :key="booth.number"
                        :class="[
                            'rounded-lg border p-3 text-center',
                            booth.isYours
                                ? 'border-primary-500 bg-primary-50 ring-2 ring-primary-200'
                                : 'border-gray-200 bg-gray-50',
                        ]"
                    >
                        <div
                            :class="[
                                'text-xs font-semibold uppercase tracking-wide',
                                booth.isYours ? 'text-primary-700' : 'text-gray-500',
                            ]"
                        >
                            Booth {{ booth.number }}
                        </div>
                        <div
                            :class="[
                                'mt-1 font-bold',
                                booth.isYours ? 'text-primary-900' : 'text-gray-800',
                            ]"
                        >
                            {{ booth.label }}
                        </div>
                        <div v-if="booth.isYours" class="mt-1 text-xs font-semibold text-primary-700">
                            Your booth
                        </div>
                    </div>
                </div>

                <p v-if="yourBooth" class="mt-4 text-sm text-gray-700">
                    Your number is <strong>{{ attendeeNumber }}</strong>, so queue at
                    <strong>booth {{ yourBooth.number }}</strong> ({{ yourBooth.label }}).
                </p>
                <p v-else class="mt-4 text-sm text-gray-600">
                    Your number is the part before the dash on your badge id, for example
                    <strong>1234</strong>-2.
                </p>
            </div>
        </template>
    </Card>
</template>
