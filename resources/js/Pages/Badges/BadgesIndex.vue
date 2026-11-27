<script setup>

import {Head, router} from "@inertiajs/vue3";
import Layout from "@/Layouts/Layout.vue";
import BadgeListItem from "@/Components/BadgeListItem.vue";
import BadgePickupInfo from "@/Components/BadgePickupInfo.vue";
import { Inbox, Info, Loader2, Plus, RefreshCw, Star, TriangleAlert } from "@lucide/vue";
import { Button } from "@/Components/UI/button";
import { Alert, AlertDescription } from "@/Components/UI/alert";
import { Card, CardTitle } from "@/Components/UI/card";
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
                        size="sm"
                        class="w-full sm:w-auto bg-success text-white hover:bg-success/90"
                    >
                        <Star/> {{ `Customize Prepaid Badge${prepaidBadgesLeft > 1 ? `s (${prepaidBadgesLeft})` : ''}` }}
                    </Button>

                    <!-- Purchase Badge Button -->
                    <Button
                        v-else-if="canCreate && event && event.allowsOrders"
                        @click="router.visit(route('badges.create'))"
                        size="sm"
                        class="w-full sm:w-auto"
                    >
                        <Plus/> Purchase Badge (5€)
                    </Button>
                </div>
            </div>

            <!-- Orders Not Yet Open Message - Full width block with margin -->
            <Alert
                v-if="event && event.orderStartsAt && new Date(event.orderStartsAt) > new Date()"
                variant="info"
                class="mt-6"
            >
                <Info/>
                <AlertDescription>
                    You may order additional badges starting {{ new Date(event.orderStartsAt).toLocaleDateString('de-DE') }}. If you have ordered additional badges trough your ticket, you may need to logout and log back in to customize them.
                </AlertDescription>
            </Alert>

        </div>

        <!-- Badge List -->
        <Card v-if="badges.length > 0" class="mt-6 gap-0 p-5">
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
        </Card>

        <!-- No Badges Message -->
        <Card v-else class="mt-6 gap-0 p-5">
            <div class="text-center py-8">
                <Inbox class="size-10 text-muted-foreground mb-4 mx-auto"/>
                <h3 class="text-lg font-semibold mb-2">No badges yet</h3>
                <p class="text-gray-600 mb-4">
                    You haven't created any fursuit badges for this event yet.
                </p>
                <Button
                    v-if="canCreate && event && event.allowsOrders"
                    @click="router.visit(route('badges.create'))"
                    :class="prepaidBadgesLeft > 0 ? 'bg-success text-white hover:bg-success/90' : ''"
                >
                    <Plus/> {{ prepaidBadgesLeft > 0 ? 'Customize Prepaid Badge' : 'Purchase Badge (5€)' }}
                </Button>
                <Button
                    v-else-if="prepaidBadgesLeft > 0"
                    @click="router.visit(route('badges.create'))"
                    class="bg-success text-white hover:bg-success/90"
                >
                    <Star/> Customize Prepaid Badge
                </Button>
            </div>
        </Card>

        <!-- Pickup Info. Booth split and opening hours are deliberately not repeated
             here - the card links to /pickup, which owns both. -->
        <div v-if="showPickupInfo" class="mt-6">
            <BadgePickupInfo/>
        </div>

        <!-- Refresh Prepaid Badges Section -->
        <div v-if="event && !prepaidBadgesLeft" class="text-center mt-6">
            <p class="text-gray-600 mb-2">Not seeing your preordered badges? Your login session might be using old registration data.</p>
            <button
                @click="refreshPrepaidBadges"
                :disabled="isRefreshing"
                class="text-primary hover:text-primary/80 underline text-sm transition-colors duration-200"
            >
                <Loader2 v-if="isRefreshing" class="inline size-3.5 mr-1 animate-spin"/>
                <RefreshCw v-else class="inline size-3.5 mr-1"/>
                {{ isRefreshing ? 'Refreshing...' : 'Refresh Now' }}
            </button>
        </div>

        <!-- Unpicked Badges from Previous Years -->
        <Card v-if="unpickedBadges && unpickedBadges.length > 0" class="mt-6 gap-0 p-5">
            <CardTitle class="text-xl font-bold leading-tight mb-2 flex items-center gap-2">
                <TriangleAlert class="size-5 text-warning"/>
                <span>Unpicked Badges from Previous Years</span>
            </CardTitle>
            <Alert variant="warning" class="mb-4">
                <TriangleAlert/>
                <AlertDescription>
                    <p>
                        <strong>Important:</strong> You have badges from previous years that have not been picked up yet.
                        Collect them at the badge desk in the Fursuit Lounge.
                    </p>
                </AlertDescription>
            </Alert>

            <div class="divide-y divide-gray-200">
                <BadgeListItem
                    v-for="badge in unpickedBadges"
                    :key="badge.id"
                    :badge="badge"
                    archived
                />
            </div>
        </Card>
    </div>
</template>

