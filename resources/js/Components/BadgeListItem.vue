<script setup>
import Tag from 'primevue/tag';
import {formatEuroFromCents} from "../helpers.js";

const props = defineProps({
    badge: Object
});

function getFursuitStatusName(status) {
    switch (status) {
        case 'pending':
            return 'Pending Review';
        case 'approved':
            return 'Approved';
        case 'rejected':
            return 'Rejected';
    }
}

function getFursuitSeverity(status) {
    switch (status) {
        case 'pending':
            return 'warning';
        case 'approved':
            return 'success';
        case 'rejected':
            return 'danger';
    }
}

function getFursuitTooltipText(status) {
    switch (status) {
        case 'pending':
            return 'This badge is pending review. We will notify you once it has been approved or rejected.';
        case 'approved':
            return 'This badge has been approved';
        case 'rejected':
            return 'This badge has been rejected, we have emailed you the reason.';
    }
}

function getBadgeStatusName(status) {
    switch (status) {
        case 'pending':
            return 'Pending Printing';
        case 'printed':
            return 'Printed';
        case 'ready_for_pickup':
            return 'Ready for Pickup';
        case 'picked_up':
            return 'Picked Up';
    }
}

function getBadgeSeverity(status) {
    switch (status) {
        case 'pending':
            return 'warning';
        case 'printed':
            return 'info';
        case 'ready_for_pickup':
            return 'success';
        case 'picked_up':
            return 'success';
    }
}

function getBadgeTooltipText(status) {
    switch (status) {
        case 'pending':
            return 'This badge is pending printing. We will notify you once it is ready for pickup.';
        case 'printed':
            return 'This badge has been printed. No further changes can be made.';
        case 'ready_for_pickup':
            return 'This badge is ready for pickup.';
        case 'picked_up':
            return 'This badge has been picked up.';
    }
}

</script>

<template>
    <div class="py-3 hover:bg-gray-50 duration-200 rounded cursor-pointer">
        <div class="flex flex-col md:flex-row text-center md:text-left">
            <div class="flex flex-col justify-center items-center">
                <img :src="badge.fursuit.image_url" alt="Badge Image" class="h-32 object-cover rounded-lg" />
            </div>
            <div class="flex flex-col justify-center p-4 flex-1">
                <h2 class="text-lg font-semibold font-main">{{ badge.fursuit.name }}</h2>
                <p>{{ badge.fursuit.species.name }}</p>
                <div class="py-1">
                    <Tag v-if="badge.status_fulfillment === 'pending'"
                        v-tooltip.bottom="getFursuitTooltipText(badge.fursuit.status)"
                        :severity="getFursuitSeverity(badge.fursuit.status)"
                        :value="getFursuitStatusName(badge.fursuit.status)" />
                    <Tag v-if="badge.fursuit.status === 'approved' && badge.status_fulfillment !== 'pending'"
                        v-tooltip.bottom="getBadgeTooltipText(badge.status_fulfillment)"
                        :severity="getBadgeSeverity(badge.status_fulfillment)"
                        :value="getBadgeStatusName(badge.status_fulfillment)" />
                </div>
            </div>
            <div v-if="badge.extra_copy" class="flex flex-col justify-center px-4 pb-1 md:p-4 gap-2">
                <!-- dual_side_print, extra_copy badges -->
                <div class="flex justify-center items-center gap-2">
                    <Tag severity="info" value="Discounted Extra Copy"></Tag>
                </div>
            </div>
            <!-- Total Price -->
            <div class="flex flex-col justify-center p-4">
                <Tag v-if="badge.status_payment === 'unpaid'" v-tooltip.bottom="'This badge has not been paid yet.'"
                    :severity="'danger'" :value="'Not Paid'" />
                <p class="text-lg font-semibold font-main">{{ formatEuroFromCents(badge.total) }}</p>
                <span class="text-xs">Price</span>
            </div>
        </div>
    </div>
</template>

<style scoped>

</style>
