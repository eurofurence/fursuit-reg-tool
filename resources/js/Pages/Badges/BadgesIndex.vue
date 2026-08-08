<script setup>

import {Head, router} from "@inertiajs/vue3";
import Layout from "@/Layouts/Layout.vue";
import BadgeListItem from "@/Components/BadgeListItem.vue";
import Button from "@/Components/UI/UiButton.vue"
import Message from "@/Components/UI/UiMessage.vue"
import PaymentInfoWidget from "@/Components/PaymentInfoWidget.vue";
import BadgePickupInfo from "@/Components/BadgePickupInfo.vue";
import Card from "@/Components/UI/UiCard.vue";
import { computed, ref } from "vue";
import axios from "axios";

defineOptions({
    layout: Layout
})
const props = defineProps({
    badges: Array,
    badgeCount: Number,
    unpickedBadges: Array,
    canCreate: Boolean,
    prepaidBadges: Number,
    prepaidBadgesLeft: Number,
    attendeeId: [String, Number],
    pickupBooths: Array,
    deskOpeningHours: { type: Array, default: () => [] },
    event: Object
});

const isRefreshing = ref(false);


async function refreshPrepaidBadges() {
    isRefreshing.value = true;

    try {
        await axios.post(route('badges.refresh-prepaid'));
        // Refresh the page to show updated data
        router.reload({ only: ['prepaidBadges', 'prepaidBadgesLeft'] });
    } catch (error) {
        console.error('Failed to refresh prepaid badges:', error);
        // Could add toast notification here
    } finally {
        isRefreshing.value = false;
    }
}

// The pickup panel is only useful once something is actually collectable: a
// badge of this event that is printing or waiting at the desk, or a leftover
// from an earlier event.
const showPickupInfo = computed(() =>
    props.badges.some((badge) => ['processing', 'ready_for_pickup'].includes(badge.status_fulfillment))
    || (props.unpickedBadges?.length ?? 0) > 0
);

const hasUnpaidBadges = computed(() =>
    props.badges.some((badge) => badge.status_payment === 'unpaid')
);
</script>

<template>
    <Head title="Manage your Fursuit Badges"/>
    <div class="site-container pt-8">
        <!-- Header Section -->
        <div class="mb-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl md:text-3xl font-semibold font-main">Your Fursuit Badges</h1>
                    <p class="text-gray-600">
                        Total badges: <span class="font-semibold">{{ badgeCount }}</span>
                        <span v-if="event && event.name"> • {{ event.name }}</span>
                        <span v-if="prepaidBadgesLeft > 0" class="ml-2 text-green-600 font-semibold">
                            • {{ prepaidBadgesLeft }} prepaid badge{{ prepaidBadgesLeft > 1 ? 's' : '' }} available
                        </span>
                    </p>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-2">
                    <!-- Prepaid Badge Button (shows even when orders are closed) -->
                    <Button
                        v-if="prepaidBadgesLeft > 0"
                        @click="router.visit(route('badges.create'))"
                        size="small"
                        severity="success"
                        icon="pi pi-star"
                        class="w-full sm:w-auto"
                        :label="`Customize Prepaid Badge${prepaidBadgesLeft > 1 ? `s (${prepaidBadgesLeft})` : ''}`"
                    />

                    <!-- Purchase Badge Button -->
                    <Button
                        v-else-if="canCreate && event && event.allowsOrders"
                        @click="router.visit(route('badges.create'))"
                        size="small"
                        icon="pi pi-plus"
                        class="w-full sm:w-auto"
                        label="Purchase Badge (5€)"
                    />
                </div>
            </div>

            <!-- Orders Not Yet Open Message - Full width block with margin -->
            <Message
                v-if="event && event.orderStartsAt && new Date(event.orderStartsAt) > new Date()"
                severity="info"
                :closable="false"
                class="mt-6"
            >
                You may order additional badges starting {{ new Date(event.orderStartsAt).toLocaleDateString('de-DE') }}. If you have ordered additional badges trough your ticket, you may need to logout and log back in to customize them.
            </Message>

        </div>

        <PaymentInfoWidget />

        <!-- Badge List -->
        <Card v-if="badges.length > 0" class="mt-6">
            <template #content>
                <div class="divide-y divide-gray-200">
                    <div
                        v-for="badge in badges"
                        :key="badge.id"
                        @click="router.visit(route('badges.show', {badge: badge.id}))"
                        class="cursor-pointer rounded-lg px-2 -mx-2 hover:bg-gray-50 transition-colors duration-150"
                    >
                        <BadgeListItem :badge="badge" />
                    </div>
                </div>
            </template>
        </Card>

        <!-- No Badges Message -->
        <Card v-else class="mt-6">
            <template #content>
                <div class="text-center py-8">
                    <i class="pi pi-inbox text-4xl text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-semibold mb-2">No badges yet</h3>
                    <p class="text-gray-600 mb-4">
                        You haven't created any fursuit badges for this event yet.
                    </p>
                    <Button
                        v-if="canCreate && event && event.allowsOrders"
                        @click="router.visit(route('badges.create'))"
                        icon="pi pi-plus"
                        :label="prepaidBadgesLeft > 0 ? 'Customize Prepaid Badge' : 'Purchase Badge (5€)'"
                        :severity="prepaidBadgesLeft > 0 ? 'success' : 'primary'"
                    />
                    <Button
                        v-else-if="prepaidBadgesLeft > 0"
                        @click="router.visit(route('badges.create'))"
                        icon="pi pi-star"
                        label="Customize Prepaid Badge"
                        severity="success"
                    />
                </div>
            </template>
        </Card>

        <!-- Pickup Info -->
        <div v-if="showPickupInfo" class="mt-6 space-y-6">
            <BadgePickupInfo
                :attendee-id="attendeeId"
                :booths="pickupBooths"
                :opening-hours="deskOpeningHours"
            />

            <Message v-if="hasUnpaidBadges" severity="warn" :closable="false">
                <strong>Open payment:</strong> pay at the desk when you collect your badge.
                We only take <strong>card</strong> on site (Mastercard, Visa, Amex) through SumUp.
            </Message>
        </div>

        <!-- Refresh Prepaid Badges Section -->
        <div v-if="event && !prepaidBadgesLeft" class="text-center mt-6">
            <p class="text-gray-600 mb-2">Not seeing your preordered badges? Your login session might be using old registration data.</p>
            <button
                @click="refreshPrepaidBadges"
                :disabled="isRefreshing"
                class="text-blue-600 hover:text-blue-800 underline text-sm transition-colors duration-200"
            >
                <i v-if="isRefreshing" class="pi pi-spin pi-spinner mr-1"></i>
                <i v-else class="pi pi-refresh mr-1"></i>
                {{ isRefreshing ? 'Refreshing...' : 'Refresh Now' }}
            </button>
        </div>

        <!-- Unpicked Badges from Previous Years -->
        <Card v-if="unpickedBadges && unpickedBadges.length > 0" class="mt-6">
            <template #title>
                <div class="flex items-center gap-2">
                    <i class="pi pi-exclamation-triangle text-orange-500"></i>
                    <span>Unpicked Badges from Previous Years</span>
                </div>
            </template>
            <template #content>
                <Message severity="warn" :closable="false" class="mb-4">
                    <strong>Important:</strong> You have badges from previous years that have not been picked up yet.
                    Collect them at the badge desk in the Fursuit Lounge.
                </Message>

                <div class="divide-y divide-gray-200">
                    <BadgeListItem
                        v-for="badge in unpickedBadges"
                        :key="badge.id"
                        :badge="badge"
                        archived
                    />
                </div>
            </template>
        </Card>
    </div>
</template>

<style scoped>

</style>
