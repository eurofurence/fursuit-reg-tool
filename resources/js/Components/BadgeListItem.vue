<script setup>
import Tag from 'primevue/tag';
import {formatEuroFromCents} from "../helpers.js";

const props = defineProps({
    badge: Object
});

function getStatusName(status) {
    switch (status) {
        case 'pending':
            return 'Pending Review';
        case 'approved':
            return 'Approved';
        case 'rejected':
            return 'Rejected';
    }
}

function getSeverity(status) {
    switch (status) {
        case 'pending':
            return 'warning';
        case 'approved':
            return 'success';
        case 'rejected':
            return 'danger';
    }
}

function getTooltipText(status) {
    switch (status) {
        case 'pending':
            return 'This badge is pending review. We will notify you once it has been approved or rejected.';
        case 'approved':
            return 'This badge has been approved';
        case 'rejected':
            return 'This badge has been rejected, we have emailed you the reason.';
    }
}
</script>

<template>
    <div class="py-3 hover:bg-gray-50 duration-200 rounded cursor-pointer">
        <div class="flex flex-col md:flex-row text-center md:text-left">
            <div class="flex flex-col justify-center items-center">
                <img :src="badge.fursuit.image_url" alt="Badge Image" class="h-32 object-cover rounded-lg"/>
            </div>
            <div class="flex flex-col justify-center p-4 flex-1">
                <h2 class="text-lg font-semibold font-main">{{ badge.fursuit.name }}</h2>
                <p>{{ badge.fursuit.species.name }}</p>
                <div class="py-1">
                    <Tag v-tooltip.bottom="getTooltipText(badge.fursuit.status)" :severity="getSeverity(badge.fursuit.status)" :value="getStatusName(badge.fursuit.status)"/>
                </div>
            </div>
            <div v-if="badge.extra_copy" class="flex flex-col justify-center px-4 pb-1 md:p-4 gap-2">
                <!-- dual_side_print, extra_copy badges -->
                <div class="flex justify-center items-center gap-2">
                    <Tag severity="info" value="Discounted Extra Copy"></Tag>
                </div>
            </div>
            <div v-if="badge.dual_side_print" class="flex flex-col justify-center px-4 pb-1 md:p-4 gap-2">
                <!-- dual_side_print, extra_copy badges -->
                <div class="flex justify-center items-center gap-2">
                    <Tag value="Dual Side Print"></Tag>
                </div>
            </div>
            <!-- Total Price -->
            <div class="flex flex-col justify-center p-4">
                <p class="text-lg font-semibold font-main">{{ formatEuroFromCents(badge.total) }}</p>
                <span class="text-xs">Price</span>
            </div>
        </div>
    </div>
</template>

<style scoped>

</style>
