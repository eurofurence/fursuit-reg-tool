<script setup>

import { Head, router, usePage } from "@inertiajs/vue3";
import Button from '@/Components/UI/UiButton.vue';
import Card from '@/Components/UI/UiCard.vue';
import dayjs from "dayjs";
import relativeTime from 'dayjs/plugin/relativeTime';
import { Link } from "@inertiajs/vue3";
import Message from '@/Components/UI/UiMessage.vue';
import { computed, ref, onMounted, onUnmounted } from "vue";
import Layout from "@/Layouts/Layout.vue";
import { formatEuroFromCents, formatPrintDate, massPrintRunIsOver } from "@/helpers.js";

dayjs.extend(relativeTime);

defineOptions({
    layout: Layout
})

const props = defineProps({
    showState: String,
    event: Object,
    prepaidBadgesLeft: Number,
    currentEventBadgeCount: Number,
    canCreate: Boolean,
    badgeSummary: Object,
    badgePrice: Number,
});

const currentTime = ref(dayjs());

// Update time every second
let timeInterval;
onMounted(() => {
    timeInterval = setInterval(() => {
        currentTime.value = dayjs();
    }, 1000);
});

onUnmounted(() => {
    if (timeInterval) {
        clearInterval(timeInterval);
    }
});

const event = computed(() => props.event || usePage().props.event);

// The free-badge cutoff comes from the event, not from a date typed into this page: the
// FAQ quotes the same field, and the two used to be able to disagree.
const freeBadgeDeadline = computed(() => {
    // `event` is either this page's trimmed payload (camelCase) or the shared prop, which
    // is the model itself (snake_case). orderStatus already copes with both; so does this.
    const deadline = event.value?.freeBadgeDeadline ?? event.value?.free_badge_deadline;

    return deadline ? dayjs(deadline).format('D MMMM YYYY') : null;
});
const user = computed(() => usePage().props.auth.user);
const prepaidBadgesLeft = computed(() => props.prepaidBadgesLeft || 0);

const amountDue = computed(() => usePage().props.auth?.amountDue ?? 0);

/*
 * "Is my badge ready" is the question people open this page to answer while standing in
 * the hall, so it is answered in one sentence above everything else. Ready badges lead,
 * because that is the part that makes somebody walk to the desk.
 */
const badgeStatusLine = computed(() => {
    const summary = props.badgeSummary;
    if (!summary || summary.total === 0) return null;

    const parts = [];
    if (summary.ready > 0) parts.push(`${summary.ready} ready for pickup`);
    if (summary.inProgress > 0) parts.push(`${summary.inProgress} still being prepared`);
    if (summary.pickedUp > 0) parts.push(`${summary.pickedUp} collected`);

    return parts.join(' \u00b7 ');
});

const userBadgeOrderedCount = computed(() => {
    if (!user.value) return null;
    return props.currentEventBadgeCount || 0;
});

const orderStatus = computed(() => {
    // Trust the backend's state determination
    if (props.showState === 'open') {
        // Show countdown only if order_ends_at is available and in the future
        const orderEndsAt = event.value?.order_ends_at ? dayjs(event.value.order_ends_at) : null;
        const timeRemaining = orderEndsAt ? orderEndsAt.diff(currentTime.value) : null;

        return {
            status: 'open',
            message: 'Badge orders are currently open',
            timeRemaining: (orderEndsAt && timeRemaining && timeRemaining > 0) ? orderEndsAt.from(currentTime.value) : null,
            severity: 'success'
        };
    }

    // Check for upcoming state only if not open - this takes precedence over user-specific states
    if (props.showState !== 'open' && event.value?.orderStartsAt) {
        const orderStartsAt = dayjs(event.value.orderStartsAt);
        if (orderStartsAt.isAfter(currentTime.value)) {
            return {
                status: 'upcoming',
                message: 'Badge orders open',
                timeRemaining: orderStartsAt.from(currentTime.value),
                severity: 'info'
            };
        }
    }

    if (user.value) { // If logged in
        if (!prepaidBadgesLeft.value && !userBadgeOrderedCount.value) { // Doesnt have a preorder badge available
            return {
                status: 'noPreorder',
                message: 'You did not pre-order any fursuit badges with your registration.',
                timeRemaining: null,
                severity: 'secondary'
            };
        }
    }

    return {
        status: 'closed',
        message: 'Badge orders are currently closed',
        timeRemaining: null,
        severity: 'secondary'
    };
});

const userBadgeStatus = computed(() => {
    if (!user.value) return null;

    const badgeCount = props.currentEventBadgeCount || 0;
    const prepaidLeft = prepaidBadgesLeft.value;

    if (prepaidLeft > 0) {
        return {
            type: 'prepaid',
            message: `You have ${prepaidLeft} prepaid badge${prepaidLeft > 1 ? 's' : ''} to customize!`,
            action: `Customize Badge${prepaidLeft > 1 ? 's' : ''}`,
            severity: 'success'
        };
    } else if (badgeCount === 0) {
        return {
            type: 'none',
            message: 'No badges ordered yet',
            action: 'Order Your First Badge',
            severity: 'info'
        };
    } else {
        return {
            type: 'ordered',
            message: `You have ${badgeCount} badge${badgeCount > 1 ? 's' : ''} ordered`,
            action: 'Order More Badges',
            severity: 'success'
        };
    }
});

const shouldShowRegMessage = computed(() => {
    if (!user.value || props.showState !== 'open') return false;

    const orderEndsAt = dayjs(event.value?.order_ends_at);
    const daysUntilClose = orderEndsAt.diff(currentTime.value, 'days');

    // Show reg message if orders close in more than 7 days
    return daysUntilClose > 7;
});
</script>

<template>

    <Head>
        <title>Fursuit Badge System - Eurofurence</title>
        <meta head-key="description" name="description"
            content="Get your personalized fursuit badge at Eurofurence for 5€ and join our exciting Catch-Em-All game. Celebrate your fursuit and connect with fellow fursuiters." />
    </Head>

    <!-- Hero Section -->
    <div class="relative z-0 mb-4 md:mb-8">
        <div class="bannerImage flex flex-col items-center justify-center px-6 py-10 md:py-16 text-white text-center">
            <div class="flex flex-col">
                    <h1 class="font-main text-4xl md:text-6xl font-bold drop-shadow-xl mb-4">
                    Eurofurence Fursuit Badge
                </h1>
                <p class="text-2xl drop-shadow-lg max-w-3xl mx-auto leading-relaxed">
                    Get your personalized badge for your character!
                </p>
                <!-- Signed out, the two facts that decide whether somebody bothers logging
                     in. They used to sit in a card 600px down the page. -->
                <p v-if="!user && badgePrice" class="mt-2 text-lg drop-shadow-lg opacity-90">
                    {{ formatEuroFromCents(badgePrice) }} per badge, and the first one is free with your registration.
                </p>

                <!-- Action Buttons -->
                <div v-if="user" class="w-full max-w-2xl mx-auto">
                    <!-- Show prepaid badge button even when orders are closed (only if creation is actually allowed) -->
                    <div v-if="canCreate && prepaidBadgesLeft > 0" class="space-y-6">
                        <div class="flex flex-col md:flex-row gap-3 mt-6">
                            <!-- Prepaid Badge Button -->
                            <Button
                                @click="router.visit(route('badges.create'))"
                                icon="pi pi-star"
                                class="flex-1 text-xl font-bold shadow-2xl transform hover:scale-105 transition-all duration-200 bg-emerald-600 hover:bg-emerald-700 border-0 text-white"
                                fluid
                                size="large"
                                :label="`Customize Prepaid Badge${prepaidBadgesLeft > 1 ? 's' : ''}`"
                            />

                            <!-- Secondary Action Button -->
                            <Button
                                v-if="currentEventBadgeCount > 0"
                                @click="router.visit(route('badges.index'))"
                                icon="pi pi-list"
                                class="flex-1 font-semibold"
                                size="large"
                                label="Manage Badges"
                            />
                        </div>
                    </div>

                    <div v-else-if="canCreate" class="space-y-6">
                        <!-- Action Buttons - Max 2 buttons side by side -->
                        <div class="flex flex-col md:flex-row gap-3 mt-6">
                            <!-- Primary Action Button -->
                            <Button
                                @click="router.visit(route('badges.create'))"
                                icon="pi pi-id-card"
                                class="flex-1 text-xl font-bold shadow-2xl transform hover:scale-105 transition-all duration-200 bg-blue-600 hover:bg-blue-700 border-0 text-white"
                                fluid
                                size="large"
                                :label="userBadgeStatus?.action || 'Create Your Badge'"
                            />

                            <!-- Secondary Action Button -->
                            <Button
                                v-if="currentEventBadgeCount > 0"
                                @click="router.visit(route('badges.index'))"
                                icon="pi pi-list"
                                class="flex-1 font-semibold"
                                size="large"
                                fluid
                                label="Manage Badges"
                            />
                            <a
                                v-else-if="shouldShowRegMessage"
                                href="https://reglive.eurofurence.org/20250105-1445-r4v1/app/register"
                                target="_blank"
                                rel="noopener"
                                class="flex-1 font-semibold"
                            >
                                <Button
                                    icon="pi pi-external-link"
                                    size="large"
                                    class="w-full"
                                    fluid
                                    label="Order More"
                                />
                            </a>
                        </div>

                    </div>

                    <!-- Upcoming State (Orders haven't started yet) -->
                    <div v-else-if="orderStatus.status === 'upcoming'" class="text-center space-y-6">
                        <p class="text-2xl mb-6 opacity-90">
                            Login to customize your fursuit badge that you have bought with your ticket. Additional badges can be ordered at a fee from {{ dayjs(event.orderStartsAt).format('D.M.YYYY') }}.
                        </p>
                        <Link
                            v-if="currentEventBadgeCount > 0"
                            :href="route('badges.index')"
                            class="w-full">
                            <Button
                                icon="pi pi-list"
                                class="flex-1 font-semibold text-xl"
                                size="large"
                                label="View My Badges"
                            />
                        </Link>
                    </div>

                    <!-- NoPreorder State -->
                    <div v-else-if="orderStatus.status === 'noPreorder'" class="text-center space-y-6">
                        <p class="text-2xl mb-6 opacity-90">You did not pre-order any fursuit badges with your registration.</p>
                        <a
                            href="https://reg.eurofurence.org"
                            target="_blank"
                            class="w-full">
                            <Button
                                icon="pi pi-ticket"
                                class="flex-1 font-semibold text-xl"
                                size="large"
                                label="Check my Registration"
                            />
                        </a>
                        <Link
                            v-if="currentEventBadgeCount > 0"
                            :href="route('badges.index')"
                            class="w-full">
                            <Button
                                icon="pi pi-list"
                                class="flex-1 font-semibold text-xl"
                                size="large"
                                label="View My Badges"
                            />
                        </Link>
                        <p class="mb-6 opacity-90">You may need to logout and log back in to make changes take effect.</p>
                    </div>

                    <!-- Closed State -->
                    <div v-else class="text-center space-y-6">
                        <p class="text-2xl mb-6 opacity-90">Badge orders are currently closed</p>
                        <Link
                            v-if="currentEventBadgeCount > 0"
                            :href="route('badges.index')"
                            class="w-full">
                            <Button
                                icon="pi pi-list"
                                class="flex-1 font-semibold text-xl"
                                size="large"
                                label="View My Badges"
                            />
                        </Link>
                    </div>
                </div>

                <!-- Login Button -->
                <div v-else class="w-full max-w-xl mx-auto">
                    <Link method="POST" :href="route('auth.login.redirect')" class="w-full">
                        <Button
                            icon="pi pi-sign-in"
                            class="w-full bg-blue-600 hover:bg-blue-700 border-0 text-white text-2xl py-4 px-8 font-bold shadow-2xl transform hover:scale-105 transition-all duration-200"
                            size="large"
                            label="Login with Eurofurence Identity"
                        />
                    </Link>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="site-container pt-3">
    <!--
      Status strip. First thing under the hero, ahead of any marketing copy: it answers
      the two questions somebody on site opens this page for, "is it ready" and "what do
      I owe". It sat above the hero for a while, which read as a utility bar bolted on top
      of the banner - the hero has to come first, this comes immediately after.
    -->
    <div v-if="user && (badgeStatusLine || amountDue > 0)" class="max-w-3xl mx-auto mb-6">
        <div class="rounded-lg bg-white shadow-sm divide-y divide-gray-100">
            <Link v-if="badgeStatusLine" :href="route('badges.index')" class="flex items-center gap-3 p-4">
                <i class="pi pi-id-card text-xl text-primary-500"></i>
                <span class="flex-1">
                    <span class="block text-xs uppercase tracking-wide text-gray-500">Your badges</span>
                    <span class="font-semibold">{{ badgeStatusLine }}</span>
                </span>
                <i class="pi pi-chevron-right text-gray-400"></i>
            </Link>

            <div v-if="amountDue > 0" class="flex items-center gap-3 p-4">
                <i class="pi pi-euro text-xl text-yellow-600"></i>
                <span class="flex-1">
                    <span class="block text-xs uppercase tracking-wide text-gray-500">Still to pay at the desk</span>
                    <span class="text-2xl font-bold font-main">{{ formatEuroFromCents(amountDue) }}</span>
                </span>
                <Link :href="route('info.pickup')" class="text-sm font-semibold text-primary-500 underline">
                    Where?
                </Link>
            </div>
        </div>
    </div>

            <!-- Flash Messages -->
            <Message v-if="usePage().props.flash.message" severity="error" :closable="true" class="mb-6">
                {{ usePage().props.flash.message }}
            </Message>

            <Message v-if="massPrintRunIsOver(event)" severity="info"
                :closable="false" class="mb-6">
                <i class="pi pi-info-circle mr-2"></i>
                The printing deadline ({{ formatPrintDate(event.mass_printed_at) }}) has passed, so
                badges ordered now can be collected from the second convention day.
            </Message>

            <!--
              The price and the free-badge deadline come from badgePrice and the event,
              never typed in here: these cards and /faq quote the same two facts, and when
              this page hardcoded "5€" and "1st August 2026" the two were free to drift.
              Each card ends in a link to the page that owns the rest of the answer.
            -->
            <div class="grid md:grid-cols-2 gap-8 mb-8 items-start">
                <!-- About Fursuit Badges -->
                <Card>
                    <template #title>
                        <div class="flex items-center gap-3">
                            <i class="pi pi-id-card text-3xl text-primary-500"></i>
                            <h2 class="text-2xl font-bold font-main">Fursuit Badges</h2>
                        </div>
                    </template>
                    <template #content>
                        <div class="space-y-4">
                            <p>
                                Get your own custom keepsake: the <strong>Eurofurence Fursuit Badge</strong> for your
                                costume and character.
                            </p>

                            <div class="border-t border-gray-200 divide-y divide-gray-200">
                                <div class="flex items-start gap-4 py-4">
                                    <i class="pi pi-tag text-xl text-gray-400 mt-1"></i>
                                    <div class="flex-1">
                                        <strong>Price</strong>
                                        <p class="mt-1 text-sm text-gray-600">
                                            <span class="text-lg font-bold text-gray-900 align-middle">{{ formatEuroFromCents(badgePrice) }}</span>
                                            <span class="align-middle"> per badge</span>
                                        </p>
                                        <p class="mt-1 text-sm text-gray-600">
                                            <template v-if="freeBadgeDeadline">
                                                First badge free if booked with your registration before
                                                {{ freeBadgeDeadline }}.
                                            </template>
                                            <template v-else>
                                                First badge free if booked with your registration.
                                            </template>
                                        </p>
                                    </div>
                                </div>

                                <div class="flex items-start gap-4 py-4">
                                    <i class="pi pi-calendar text-xl text-gray-400 mt-1"></i>
                                    <div class="flex-1">
                                        <strong>Pickup</strong>
                                        <ul class="mt-1 space-y-1 text-sm text-gray-600 list-disc pl-5">
                                            <li>At the desk in the Fursuit Lounge</li>
                                            <li>You do <strong>not</strong> need to bring your fursuit</li>
                                            <li v-if="freeBadgeDeadline">
                                                Ordered until {{ freeBadgeDeadline }}: pre-printed, ready on day 1
                                            </li>
                                            <li v-else>Ordered before the pre-print run: ready on day 1</li>
                                            <li>Ordered afterwards: printed on site, from day 2</li>
                                            <li>
                                                Anything still to pay at the desk is
                                                <strong>card only</strong> (Mastercard, Visa, Amex) via SumUp
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-x-5 gap-y-2">
                                <Link :href="route('info.faq')" class="font-semibold text-primary-500 underline w-fit">
                                    Prices and payment in detail
                                </Link>
                                <Link :href="route('info.pickup')" class="font-semibold text-primary-500 underline w-fit">
                                    Pickup times and booths
                                </Link>
                            </div>
                        </div>
                    </template>
                </Card>

                <!-- Catch-Em-All Game -->
                <Card>
                    <template #title>
                        <div class="flex items-center gap-3">
                            <i class="pi pi-trophy text-3xl text-primary-500"></i>
                            <h2 class="text-2xl font-bold font-main">Catch-Em-All Game</h2>
                        </div>
                    </template>
                    <template #content>
                        <div class="space-y-4">
                            <p>
                                Join our community game and collect as many fursuit badges as you can. Meet fellow
                                fursuiters, make friends, and compete for the top spot.
                            </p>

                            <div class="border-t border-gray-200 divide-y divide-gray-200">
                                <div class="flex items-start gap-4 py-4">
                                    <i class="pi pi-hashtag text-xl text-gray-400 mt-1"></i>
                                    <div class="flex-1">
                                        <strong>Scan the badges you meet</strong>
                                        <p class="mt-1 text-sm text-gray-600">
                                            Every fursuit badge carries a 5-character code. Enter somebody's code and
                                            you have caught that fursuit.
                                        </p>
                                    </div>
                                </div>

                                <div class="flex items-start gap-4 py-4">
                                    <i class="pi pi-users text-xl text-gray-400 mt-1"></i>
                                    <div class="flex-1">
                                        <strong>Collect and unlock</strong>
                                        <p class="mt-1 text-sm text-gray-600">
                                            Caught fursuits land in your collection, and catching enough of them
                                            unlocks achievements.
                                        </p>
                                    </div>
                                </div>

                                <div class="flex items-start gap-4 py-4">
                                    <i class="pi pi-star text-xl text-gray-400 mt-1"></i>
                                    <div class="flex-1">
                                        <strong>Climb the leaderboard</strong>
                                        <p class="mt-1 text-sm text-gray-600">
                                            Catches count towards a live ranking, and the top collector is announced
                                            at the closing ceremony.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <Link :href="route('info.catch-em-all')" class="font-semibold text-primary-500 underline w-fit block">
                                How Catch-Em-All works
                            </Link>
                        </div>
                    </template>
                </Card>
            </div>
        </div>
    </div>
</template>

<style>
.bannerImage {
    background-color: #f3f4f6;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    background-image: linear-gradient(rgba(20, 20, 20, 0.75),
            rgba(20, 20, 20, 0.75)), url("../../assets/images/banner-mobile.jpg");
}

@media (min-width: 405px) {
    .bannerImage {
        background-image: linear-gradient(rgba(20, 20, 20, 0.75),
                rgba(20, 20, 20, 0.75)), url("../../assets/images/banner-desktop.jpg");
    }
}
</style>
