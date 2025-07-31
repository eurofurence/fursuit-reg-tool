<script setup>

import { Head, router, usePage } from "@inertiajs/vue3";
import Button from 'primevue/button';
import Card from 'primevue/card';
import Chip from 'primevue/chip';
import Tag from 'primevue/tag';
import dayjs from "dayjs";
import relativeTime from 'dayjs/plugin/relativeTime';
import { Link } from "@inertiajs/vue3";
import Message from 'primevue/message';
import { computed, ref, onMounted, onUnmounted } from "vue";
import Layout from "@/Layouts/Layout.vue";
import PaymentInfoWidget from "@/Components/PaymentInfoWidget.vue";

dayjs.extend(relativeTime);

defineOptions({
    layout: Layout
})

const props = defineProps({
    showState: String
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

const event = computed(() => usePage().props.event);
const user = computed(() => usePage().props.auth.user);

const orderStatus = computed(() => {
    if (props.showState === 'open') {
        const orderEndsAt = dayjs(event.value?.order_ends_at);
        const timeRemaining = orderEndsAt.diff(currentTime.value);

        if (timeRemaining > 0) {
            return {
                status: 'open',
                message: 'Badge orders are currently open',
                timeRemaining: orderEndsAt.from(currentTime.value),
                severity: 'success'
            };
        }
    }

    if (event.value?.order_starts_at) {
        const orderStartsAt = dayjs(event.value.order_starts_at);
        if (orderStartsAt.isAfter(currentTime.value)) {
            return {
                status: 'upcoming',
                message: 'Badge orders open',
                timeRemaining: orderStartsAt.from(currentTime.value),
                severity: 'info'
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

    const hasFreeBadge = user.value.has_free_badge;
    const badgeCount = user.value.badges?.length || 0;
    const freeBadgeCopies = user.value.free_badge_copies || 0;

    if (badgeCount === 0 && hasFreeBadge) {
        return {
            type: 'unclaimed',
            message: 'You have a badge waiting to be claimed!',
            action: 'Claim Your Badge',
            severity: 'warn'
        };
    } else if (badgeCount === 0 && !hasFreeBadge) {
        return {
            type: 'none',
            message: 'No badges ordered yet',
            action: 'Order Your First Badge',
            severity: 'info'
        };
    } else if (freeBadgeCopies > 0) {
        return {
            type: 'additional_free',
            message: `You have ${freeBadgeCopies} additional badge${freeBadgeCopies > 1 ? 's' : ''} available!`,
            action: 'Claim Additional Badges',
            severity: 'warn'
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
            content="Get your personalized fursuit badge at Eurofurence! Enjoy one free badge with registration and join our exciting Catch-Em-All game. Celebrate your fursuit and connect with fellow fursuiters." />
    </Head>

    <!-- Hero Section -->
    <div class="relative z-0">
        <div class="bannerImage flex flex-col items-center justify-center px-6 py-32 text-white text-center">
            <div class="flex flex-col">
                    <h1 class="font-main text-4xl md:text-6xl font-bold drop-shadow-xl mb-4">
                    Eurofurence Fursuit Badge
                </h1>
                <p class="text-2xl drop-shadow-lg max-w-3xl mx-auto leading-relaxed">
                    Get your personalized badge for your character!
                </p>

                
                <!-- Action Buttons -->
                <div v-if="user" class="w-full max-w-2xl mx-auto">
                    <div v-if="orderStatus.status === 'open'" class="space-y-6">
                        <!-- Action Buttons - Max 2 buttons side by side -->
                        <div class="flex flex-row gap-3 mt-6">
                            <!-- Primary Action Button -->
                            <Button 
                                @click="router.visit(route('badges.create'))" 
                                icon="pi pi-id-card"
                                class="flex-1 text-xl font-bold shadow-2xl transform hover:scale-105 transition-all duration-200 bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 border-0 text-white"
                                fluid
                                size="large"
                                :label="userBadgeStatus?.action || 'Create Your Badge'"
                            />
                            
                            <!-- Secondary Action Button -->
                            <Button 
                                v-if="user.badges?.length > 0"
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
                        
                        <!-- Status Info -->
                        <div v-if="user.badges?.length > 0" class="flex justify-center mt-6">
                            <div class="bg-green-500/90 backdrop-blur-sm rounded-lg px-6 py-2 text-white shadow-lg">
                                <i class="pi pi-check mr-2"></i>
                                {{ user.badges.length }} Badge{{ user.badges.length > 1 ? 's' : '' }} Ordered
                            </div>
                        </div>
                    </div>
                    
                    <!-- Closed State -->
                    <div v-else class="text-center space-y-6">
                        <p class="text-2xl mb-6 opacity-90">Badge orders are currently closed</p>
                        <Button 
                            v-if="user.badges?.length > 0"
                            @click="router.visit(route('badges.index'))" 
                            icon="pi pi-list" 
                            class="flex-1 bg-white/90 text-gray-800 border-2 border-white hover:bg-white font-semibold text-xl py-4 shadow-lg"
                            size="large"
                            label="View My Badges"
                        />
                    </div>
                </div>
                
                <!-- Login Button -->
                <div v-else class="w-full max-w-xl mx-auto">
                    <Link method="POST" :href="route('auth.login.redirect')" class="w-full">
                        <Button 
                            icon="pi pi-sign-in" 
                            class="w-full bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 border-0 text-white text-2xl py-4 px-8 font-bold shadow-2xl transform hover:scale-105 transition-all duration-200"
                            size="large"
                            label="Login with Eurofurence Identity"
                        />
                    </Link>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="px-6 xl:px-0 max-w-6xl mx-auto pt-3">
            <!-- Flash Messages -->
            <Message v-if="usePage().props.flash.message" severity="error" :closable="true" class="mb-6">
                {{ usePage().props.flash.message }}
            </Message>

            <Message v-if="event?.mass_printed_at && new Date(event.mass_printed_at) < new Date()" severity="info"
                :closable="false" class="mb-6">
                <i class="pi pi-info-circle mr-2"></i>
                Any badge orders placed now will be available for pickup starting from the 2nd convention day.
            </Message>

            <PaymentInfoWidget class="mb-8" />

            <!-- Content Grid -->
            <div class="grid md:grid-cols-2 gap-8 mb-8 items-start">
                <!-- About Fursuit Badges -->
                <Card>
                    <template #title>
                        <div class="flex items-center gap-3">
                            <i class="pi pi-id-card text-3xl text-blue-500"></i>
                            <h2 class="text-2xl font-bold font-main">Fursuit Badges</h2>
                        </div>
                    </template>
                    <template #content>
                        <div class="space-y-4">
                            <p>
                                At Eurofurence, over <strong>40% of our attendees are fursuiters</strong>, making us one
                                of the top furry conventions with the highest number of costumers. These personalized
                                badges help you stand out and connect with the community.
                            </p>
                            <div class="space-y-6">
                                <!-- Before Registration Deadline -->
                                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                                    <h3 class="font-semibold text-green-800 mb-3 flex items-center">
                                        <i class="pi pi-calendar mr-2"></i>
                                        Before 10th July 2025 (Official Registration Period)
                                    </h3>
                                    <div class="space-y-3">
                                        <div class="flex items-start gap-3">
                                            <div class="w-16 flex-shrink-0">
                                                <Chip label="FREE" class="bg-green-100 text-green-800 w-full justify-center" />
                                            </div>
                                            <div class="flex-1">
                                                <strong>First Badge</strong>
                                                <p class="text-sm text-gray-600">Free for all registered fursuiters</p>
                                            </div>
                                        </div>
                                        <div class="flex items-start gap-3">
                                            <div class="w-16 flex-shrink-0">
                                                <Chip label="2€" class="bg-blue-100 text-blue-800 w-full justify-center" />
                                            </div>
                                            <div class="flex-1">
                                                <strong>Additional Badges</strong>
                                                <p class="text-sm text-gray-600">Extra badges for multiple fursuits</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- After Registration Deadline -->
                                <div class="bg-orange-50 border border-orange-200 rounded-lg p-4">
                                    <h3 class="font-semibold text-orange-800 mb-3 flex items-center">
                                        <i class="pi pi-calendar mr-2"></i>
                                        After 10th July 2025 (Late Orders)
                                    </h3>
                                    <div class="space-y-3">
                                        <div class="flex items-start gap-3">
                                            <div class="w-16 flex-shrink-0">
                                                <Chip label="2€" class="bg-orange-100 text-orange-800 w-full justify-center" />
                                            </div>
                                            <div class="flex-1">
                                                <strong>All Badges</strong>
                                                <p class="text-sm text-gray-600">All badges cost 2€ each, including first badge</p>
                                            </div>
                                        </div>
                                        <div class="bg-orange-100 rounded-md p-3">
                                            <p class="text-sm text-orange-800">
                                                <i class="pi pi-info-circle mr-1"></i>
                                                <strong>Pickup:</strong> Available from the 2nd convention day
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </Card>

                <!-- Catch-Em-All Game -->
                <Card>
                    <template #title>
                        <div class="flex items-center gap-3">
                            <i class="pi pi-trophy text-3xl text-yellow-500"></i>
                            <h2 class="text-2xl font-bold font-main">Catch-Em-All Game</h2>
                        </div>
                    </template>
                    <template #content>
                        <div class="flex flex-col gap-4">
                            <p>
                                Join our exciting community game and collect as many fursuit badges as you can! Meet
                                fellow fursuiters, make friends, and compete for the top spot.
                            </p>
                            <div class="space-y-3 flex-1">
                                <div class="flex items-center gap-3">
                                    <i class="pi pi-hashtag text-xl text-gray-600"></i>
                                    <span><strong>Enter 5-character codes</strong> from other fursuiters' badges</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <i class="pi pi-users text-xl text-gray-600"></i>
                                    <span><strong>Meet the community</strong> and make new friends</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <i class="pi pi-star text-xl text-gray-600"></i>
                                    <span><strong>Compete for leaderboard</strong> recognition</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <i class="pi pi-trophy text-xl text-gray-600"></i>
                                    <span><strong>Top collector gets announced</strong> at the closing ceremony</span>
                                </div>
                            </div>
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                <div class="flex items-center gap-2">
                                    <i class="pi pi-lightbulb text-yellow-600"></i>
                                    <strong class="text-yellow-800">Pro Tip:</strong>
                                </div>
                                <p class="text-yellow-700 text-sm mt-1">
                                    The more badges you collect, the higher you'll rank on the leaderboard!
                                </p>
                            </div>
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
