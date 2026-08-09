<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import dayjs from 'dayjs';
import { QrCode, Sparkles, Trophy } from 'lucide-vue-next';
import Layout from '@/Layouts/Layout.vue';

defineOptions({ layout: Layout });

const props = defineProps({
    event: { type: Object, default: null },
    gameUrl: { type: String, required: true },
    isActive: { type: Boolean, default: false },
    startsAt: { type: String, default: null },
    endsAt: { type: String, default: null },
});

// The game runs in its own window, which is usually the convention itself but can be set
// per event. Falling back to the event dates keeps the sentence sensible either way.
//
// A half-configured event can leave the two ends crossed - a start copied from one year
// and an end from another - and "18 September to 24 August" reads as a broken page. Show
// nothing rather than a range that runs backwards.
const playWindow = computed(() => {
    const from = props.startsAt ?? props.event?.startsAt;
    const until = props.endsAt ?? props.event?.endsAt;

    if (!from || !until || dayjs(until).isBefore(dayjs(from))) return null;

    return `${dayjs(from).format('D MMMM')} – ${dayjs(until).format('D MMMM YYYY')}`;
});

const steps = [
    {
        icon: QrCode,
        title: 'Scan the badges you meet',
        text: 'Every fursuit badge carries a code. Point the game at somebody\'s badge and you have caught that fursuit.',
    },
    {
        icon: Sparkles,
        title: 'Collect the ones you catch',
        text: 'Caught fursuits land in your collection, and catching enough of them unlocks achievements.',
    },
    {
        icon: Trophy,
        title: 'Climb the leaderboard',
        text: 'Catches count towards a live ranking of everyone playing. It is refreshed throughout the convention.',
    },
];
</script>

<template>
    <Head title="Catch-Em-All"/>

    <div class="site-container pt-6">
        <h1 class="text-2xl font-bold">Catch-Em-All</h1>
        <p class="text-gray-600 mt-1">
            The badge scanning game that runs alongside
            <template v-if="event">{{ event.name }}</template>
            <template v-else>the convention</template>.
        </p>

        <div class="mt-5 rounded-lg bg-white shadow-sm p-5">
            <p v-if="isActive" class="font-semibold">The game is running right now.</p>
            <p v-else class="font-semibold">The game is not running at the moment.</p>
            <p v-if="playWindow" class="text-sm text-gray-600 mt-1">Play window: {{ playWindow }}.</p>

            <a
                :href="gameUrl"
                class="inline-flex items-center gap-2 mt-4 rounded-md px-5 py-3 font-semibold text-white transition-colors"
                :class="isActive ? 'bg-primary-500 hover:bg-primary-600' : 'bg-gray-400 pointer-events-none'"
                :aria-disabled="!isActive"
                :tabindex="isActive ? undefined : -1"
            >
                <Trophy class="h-5 w-5"/>
                Start Catch-Em-All
            </a>

            <p class="text-xs text-gray-500 mt-2">
                The game opens in its own app and asks you to sign in once.
            </p>
        </div>

        <section class="mt-8 grid gap-4 sm:grid-cols-3">
            <div v-for="step in steps" :key="step.title" class="rounded-lg bg-white shadow-sm p-5">
                <span class="grid place-items-center h-9 w-9 rounded-lg bg-primary-500/10 text-primary-500">
                    <component :is="step.icon" class="h-5 w-5"/>
                </span>
                <h2 class="font-bold mt-3">{{ step.title }}</h2>
                <p class="text-sm text-gray-600 mt-1">{{ step.text }}</p>
            </div>
        </section>

        <section class="mt-8">
            <h2 class="font-bold text-lg">Do I need anything?</h2>
            <p class="text-gray-600 text-sm mt-1">
                A fursuit badge of your own is what other people scan, so ordering one puts you in the
                game.
            </p>
        </section>
    </div>
</template>
