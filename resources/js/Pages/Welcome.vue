<script setup>

import { Head, router, usePage } from "@inertiajs/vue3";
import Button from '@/Components/UI/UiButton.vue';
import dayjs from "dayjs";
import relativeTime from 'dayjs/plugin/relativeTime';
import { Link } from "@inertiajs/vue3";
import Message from '@/Components/UI/UiMessage.vue';
import { computed, ref, onMounted, onUnmounted } from "vue";
import Layout from "@/Layouts/Layout.vue";
import { formatEuroFromCents } from "@/helpers.js";

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

const teasers = computed(() => [
    { label: 'What it costs', hint: 'Prices, free badges, paying', href: route('info.faq'), icon: 'pi pi-euro' },
    { label: 'Where to collect it', hint: 'Badge desk and booths', href: route('info.pickup'), icon: 'pi pi-map-marker' },
    { label: 'Catch-Em-All', hint: 'The badge scanning game', href: route('info.catch-em-all'), icon: 'pi pi-trophy' },
]);

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

    <!--
      Status strip. Deliberately the first thing under the header rather than another card
      further down: on a phone the hero used to push both of these below the fold, and the
      amount due landed underneath the fixed tab bar. Answers the two on-site questions,
      "is it ready" and "what do I owe", before anything else on the page.
    -->
    <div v-if="user && (badgeStatusLine || amountDue > 0)" class="site-container pt-4">
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

    <!-- Hero Section -->
    <div class="relative z-0 mb-4 md:mb-8">
        <div class="bannerImage flex flex-col items-center justify-center px-6 py-10 md:py-32 text-white text-center">
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
            <!-- Flash Messages -->
            <Message v-if="usePage().props.flash.message" severity="error" :closable="true" class="mb-6">
                {{ usePage().props.flash.message }}
            </Message>

            <Message v-if="event?.mass_printed_at && new Date(event.mass_printed_at) < new Date()" severity="info"
                :closable="false" class="mb-6">
                <i class="pi pi-info-circle mr-2"></i>
                Badges ordered now are printed on site and can be picked up from the 2nd convention day
                (on day 1 only while nobody is waiting at the desk).
            </Message>

            <!--
              The two long cards that used to live here restated /faq, /pickup and
              /catch-em-all - about half the document, and on a phone they pushed
              everything actionable off the first screen. Worse, the pickup copy had
              drifted: it named a different desk than /pickup does and left out the booth
              split entirely. Three links to the pages that own the answers instead.

              PaymentInfoWidget went with them: the amount due is in the status strip at
              the top now, labelled, rather than as a bare number halfway down the page.
            -->
            <div class="grid gap-3 sm:grid-cols-3 mb-8">
                <Link
                    v-for="teaser in teasers"
                    :key="teaser.label"
                    :href="teaser.href"
                    class="bg-white rounded-lg shadow-sm p-4 flex items-center gap-3 hover:shadow transition-shadow"
                >
                    <i :class="teaser.icon" class="text-xl text-primary-500"></i>
                    <span class="flex-1">
                        <span class="block font-semibold">{{ teaser.label }}</span>
                        <span class="block text-sm text-gray-500">{{ teaser.hint }}</span>
                    </span>
                    <i class="pi pi-chevron-right text-gray-400"></i>
                </Link>
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
