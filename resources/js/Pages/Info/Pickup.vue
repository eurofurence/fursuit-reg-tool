<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import dayjs from 'dayjs';
import { Clock, MapPin, Users } from 'lucide-vue-next';
import Layout from '@/Layouts/Layout.vue';

defineOptions({ layout: Layout });

const props = defineProps({
    event: { type: Object, default: null },
    booths: { type: Array, default: () => [] },
    // [{ date: 'YYYY-MM-DD', opens, closes, note }] from Settings > On-Site Desk. Empty
    // until the desk team publishes hours, and the section is dropped entirely when it is.
    openingHours: { type: Array, default: () => [] },
    attendeeId: { type: Number, default: null },
    myBoothIndex: { type: Number, default: null },
    // Decided on the server: the booth split only runs on the desk's first day, so every
    // viewer gets the same answer instead of one that depends on their device clock.
    boothsActive: { type: Boolean, default: false },
    // 'YYYY-MM-DD' of that day, so the section can name the day it is talking about.
    boothDay: { type: String, default: null },
});

const myBooth = computed(() =>
    props.myBoothIndex === null ? null : props.booths[props.myBoothIndex] ?? null
);

/*
 * The booths are rendered in the order they physically stand in the hall: booth 1 on the
 * right, counting leftwards. `flex-row-reverse` walks the same array the desk signs are
 * numbered from, so the numbering stays 1..n and only the direction flips - reversing the
 * array instead would make every index off by one against `myBoothIndex`.
 */
const showBooths = computed(() => props.boothsActive && props.booths.length > 0);

// The booth range split across two lines, because six booths sharing a phone's width have
// no room for "1000 – 1999" on one. The dash stays on the second line: without it the card
// reads as two unrelated numbers rather than as a range.
const boothFrom = (booth) => String(booth.from);
const boothTo = (booth) => (booth.to === null || booth.to === undefined ? 'and up' : `– ${booth.to}`);

// The weekday is derived from the stored date rather than stored beside it, so the two
// can never disagree. "Today" is what someone standing in the hall is looking for.
const dayLabel = (date) => dayjs(date).format('dddd, D MMMM');

// The aside is a narrow column, where the full weekday wraps and separates a day from its
// own opening time.
const shortDayLabel = (date) => dayjs(date).format('ddd, D MMM');

const isToday = (date) => dayjs(date).isSame(dayjs(), 'day');

const dateRange = computed(() => {
    if (!props.event?.startsAt || !props.event?.endsAt) return null;

    return `${dayjs(props.event.startsAt).format('D MMMM')} – ${dayjs(props.event.endsAt).format('D MMMM YYYY')}`;
});
</script>

<template>
    <Head title="Badge Pickup"/>

    <div class="site-container pt-6">
        <h1 class="text-2xl font-bold">Badge Pickup</h1>
        <p class="text-gray-600 mt-1">
            Collect your fursuit badge at the badge desk
            <template v-if="event"> during {{ event.name }}</template>
            <template v-if="dateRange">, {{ dateRange }}</template>.
        </p>

        <!-- Two tracks, side by side on desktop. "Where do I stand" is a decision an
             attendee makes once and it needs the width; "when is it open" is a short
             reference list that read as a full-width table of two columns and a lot of
             whitespace. They also answer different questions, so interleaving them read
             as one confused block. -->
        <div class="mt-6 lg:flex lg:items-start lg:gap-8">
            <div class="min-w-0 lg:flex-1">
                <!-- Personal answer first, and on its own: the general booth map below is
                     for everyone, this line is for the person reading it. -->
                <section v-if="showBooths && myBooth">
                    <h2 class="font-bold text-lg">Your booth</h2>
                    <div class="mt-3 rounded-lg bg-primary-500 text-white p-5 flex items-start gap-4">
                        <MapPin class="h-6 w-6 shrink-0 mt-0.5"/>
                        <div>
                            <div class="text-sm text-white/75">
                                Your attendee number is {{ attendeeId }}, so you queue at
                            </div>
                            <div class="text-xl font-bold mt-0.5">
                                Booth {{ myBoothIndex + 1 }} &middot; attendee numbers {{ myBooth.label }}
                            </div>
                        </div>
                    </div>
                </section>

                <section v-if="showBooths" :class="showBooths && myBooth ? 'mt-8' : ''">
                    <h2 class="font-bold text-lg">Which booth serves which numbers?</h2>
                    <p class="text-gray-600 text-sm mt-1">
                        <template v-if="boothDay">On {{ dayLabel(boothDay) }} the</template>
                        <template v-else>On the busy first day the</template>
                        desk runs several booths in parallel, each handing out badges for its own
                        range of attendee numbers. You join the one queue and are sent to the booth
                        that matches the attendee number printed on your convention badge.
                    </p>

                    <!-- Laid out as the booths stand in the hall: booth 1 on the right, counting
                         leftwards. Matching the physical row is the whole point, so this stays a
                         single line at every width rather than wrapping into a grid. -->
                    <ul class="mt-4 flex flex-row-reverse items-stretch gap-1.5 sm:gap-2">
                        <li
                            v-for="(booth, index) in booths"
                            :key="booth.label"
                            class="flex-1 basis-0 min-w-0 rounded-lg bg-white px-1 py-3 text-center shadow-sm"
                            :class="index === myBoothIndex ? 'ring-2 ring-primary-500' : ''"
                        >
                            <div
                                class="text-[10px] sm:text-xs font-bold uppercase tracking-wide"
                                :class="index === myBoothIndex ? 'text-primary-700' : 'text-gray-500'"
                            >
                                Booth {{ index + 1 }}
                            </div>
                            <!-- Without this caption the card is three bare numbers and the
                                 booth number reads as part of the range. -->
                            <div class="mt-2 text-[9px] sm:text-[10px] uppercase tracking-wide text-gray-400 leading-tight">
                                Attendee no.
                            </div>
                            <div class="font-bold leading-tight text-sm sm:text-base">{{ boothFrom(booth) }}</div>
                            <div class="text-xs sm:text-sm text-gray-600 leading-tight">{{ boothTo(booth) }}</div>
                            <div
                                v-if="index === myBoothIndex"
                                class="mt-1 text-[10px] sm:text-xs font-bold text-primary-700"
                            >
                                Yours
                            </div>
                        </li>
                    </ul>

                    <!-- One queue feeding every booth, drawn rather than described: the
                         common mistake is assuming each booth has its own line and picking
                         the shortest one. -->
                    <div class="mt-2 flex items-center gap-3">
                        <div class="h-px flex-1 bg-gray-300"></div>
                        <span class="flex items-center gap-1.5 text-xs font-semibold text-gray-500">
                            <Users class="h-4 w-4"/>
                            One queue for all booths
                        </span>
                        <div class="h-px flex-1 bg-gray-300"></div>
                    </div>

                    <p class="mt-3 text-xs text-gray-500">
                        Booths are shown the way you face them: booth 1 is on the right.
                    </p>
                </section>

                <!-- Copy below states only what the system enforces. Anything about locations or
                     proxy pickup has to come from the badge desk team before it goes on this page;
                     opening hours now have a real home in Settings > On-Site Desk, so they are
                     rendered beside this rather than written here. -->
                <section :class="showBooths ? 'mt-8' : ''">
                    <h2 class="font-bold text-lg">Before you come over</h2>
                    <ul class="mt-2 text-gray-600 text-sm flex flex-col gap-2">
                        <li>Bring your convention badge. The desk finds your order by your attendee number.</li>
                        <li>Badges have to be paid before they are handed out. You can settle up at the desk.</li>
                    </ul>
                </section>
            </div>

            <!-- Opening hours, when the desk has published any. No fallback text: an invented
                 time is worse than none, and the panel is where a real one is entered. -->
            <aside v-if="openingHours.length" class="mt-8 lg:mt-0 lg:w-72 lg:shrink-0">
                <h2 class="font-bold text-lg flex items-center gap-2">
                    <Clock class="h-5 w-5 text-primary-600"/>
                    When is the desk open?
                </h2>

                <ul class="mt-3 divide-y divide-gray-100 rounded-lg bg-white shadow-sm">
                    <li
                        v-for="(row, index) in openingHours"
                        :key="index"
                        class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 px-4 py-3"
                        :class="isToday(row.date) ? 'bg-primary-50' : ''"
                    >
                        <!-- Short weekday in the column: "Wednesday, 2 September" wraps to two
                             lines at this width and pushes the time away from the day it belongs to. -->
                        <span class="font-semibold text-sm">
                            {{ shortDayLabel(row.date) }}
                            <span v-if="isToday(row.date)" class="ml-1 text-xs font-bold text-primary-700">Today</span>
                        </span>
                        <span class="text-gray-700 text-sm tabular-nums">{{ row.opens }} &ndash; {{ row.closes }}</span>
                        <span v-if="row.note" class="w-full text-xs text-gray-500">{{ row.note }}</span>
                    </li>
                </ul>
            </aside>
        </div>
    </div>
</template>
