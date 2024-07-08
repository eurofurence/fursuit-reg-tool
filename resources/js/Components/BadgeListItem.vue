<script setup>
import Tag from 'primevue/tag';
import {formatEuroFromCents} from "../helpers.js";

const props = defineProps({
    badge: Object
});
</script>

<template>
    <div class="py-3 hover:bg-gray-50 duration-200 rounded cursor-pointer">
        <div class="flex flex-row">
            <div class="flex flex-col justify-center items-center">
                <img :src="badge.fursuit.image_url" alt="Badge Image" class="h-32 object-cover rounded-lg"/>
            </div>
            <div class="flex flex-col justify-center p-4 flex-1">
                <h2 class="text-lg font-semibold font-main">{{ badge.fursuit.name }}</h2>
                <p>{{ badge.fursuit.species.name }}</p>
            </div>
            <div v-if="badge.extra_copy" class="flex flex-col justify-center p-4 gap-2">
                <!-- dual_side_print, extra_copy badges -->
                <div class="flex justify-center items-center gap-2">
                    <Tag severity="info" value="Discounted Extra Copy"></Tag>
                </div>
            </div>
            <div v-if="badge.dual_side_print" class="flex flex-col justify-center p-4 gap-2">
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
