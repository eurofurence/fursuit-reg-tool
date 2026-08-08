<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import dayjs from 'dayjs';
import { Clock, MapPin } from 'lucide-vue-next';
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
});

const myBooth = computed(() =>
    props.myBoothIndex === null ? null : props.booths[props.myBoothIndex] ?? null
);

// The weekday is derived from the stored date rather than stored beside it, so the two
// can never disagree. "Today" is what someone standing in the hall is looking for.
const dayLabel = (date) => dayjs(date).format('dddd, D MMMM');

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

        <!-- The one thing a person standing in the hall needs: which queue is theirs. -->
        <div
            v-if="myBooth"
            class="mt-5 rounded-lg bg-primary-500 text-white p-5 flex items-start gap-4"
        >
            <MapPin class="h-6 w-6 shrink-0 mt-0.5"/>
            <div>
                <div class="text-sm text-white/75">Your attendee number is {{ attendeeId }}, so you queue at</div>
                <div class="text-xl font-bold mt-0.5">Booth {{ myBoothIndex + 1 }} &middot; {{ myBooth.label }}</div>
            </div>
        </div>

        <!-- Opening hours, when the desk has published any. No fallback text: an invented
             time is worse than none, and the panel is where a real one is entered. -->
        <section v-if="openingHours.length" class="mt-6">
            <h2 class="font-bold text-lg flex items-center gap-2">
                <Clock class="h-5 w-5 text-primary-600"/>
                When is the desk open?
            </h2>

            <ul class="mt-3 divide-y divide-gray-100 rounded-lg bg-white shadow-sm">
                <li
                    v-for="(row, index) in openingHours"
                    :key="index"
                    class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 py-3"
                    :class="isToday(row.date) ? 'bg-primary-50' : ''"
                >
                    <span class="font-semibold">
                        {{ dayLabel(row.date) }}
                        <span v-if="isToday(row.date)" class="ml-1 text-xs font-bold text-primary-700">Today</span>
                    </span>
                    <span class="text-gray-700">{{ row.opens }} &ndash; {{ row.closes }}</span>
                    <span v-if="row.note" class="w-full text-sm text-gray-500">{{ row.note }}</span>
                </li>
            </ul>
        </section>

        <section class="mt-6">
            <h2 class="font-bold text-lg">Which booth do I go to?</h2>
            <p class="text-gray-600 text-sm mt-1">
                On the busy first day the desk runs several booths in parallel, each serving a range of
                attendee numbers. Your attendee number is the first part of your badge number: badge
                <span class="font-semibold">1234-2</span> belongs to attendee <span class="font-semibold">1234</span>.
            </p>

            <ul class="mt-4 grid gap-2 sm:grid-cols-2">
                <li
                    v-for="(booth, index) in booths"
                    :key="booth.label"
                    class="rounded-lg bg-white p-4 flex items-center justify-between shadow-sm"
                    :class="index === myBoothIndex ? 'ring-2 ring-primary-500' : ''"
                >
                    <span class="font-semibold">Booth {{ index + 1 }}</span>
                    <span class="text-gray-600">{{ booth.label }}</span>
                </li>
            </ul>
        </section>

        <!-- Copy below states only what the system enforces. Anything about locations or
             proxy pickup has to come from the badge desk team before it goes on this page;
             opening hours now have a real home in Settings > On-Site Desk, so they are
             rendered above rather than written here. -->
        <section class="mt-8">
            <h2 class="font-bold text-lg">Before you come over</h2>
            <ul class="mt-2 text-gray-600 text-sm flex flex-col gap-2">
                <li>Bring your convention badge. The desk finds your order by your attendee number.</li>
                <li>Badges have to be paid before they are handed out. You can settle up at the desk.</li>
            </ul>
        </section>
    </div>
</template>
