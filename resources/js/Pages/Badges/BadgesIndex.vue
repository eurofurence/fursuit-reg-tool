<script setup>

import {Head, router} from "@inertiajs/vue3";
import Layout from "@/Layouts/Layout.vue";
import BadgeListItem from "@/Components/BadgeListItem.vue";
import Button from "primevue/button"
import Message from "primevue/message"
import PaymentInfoWidget from "@/Components/PaymentInfoWidget.vue";

defineOptions({
    layout: Layout
})
const props = defineProps({
    badges: Array,
    canCreate: Boolean
});
</script>

<template>
    <Head title="Manage your Fursuit Badges"/>
    <div class="pt-8 px-6 xl:px-0">
        <div class="mb-8">
            <h1 class="text-xl sm:text-2xl md:text-3xl font-semibold font-main">Your Fursuit Badges</h1>
            <p>
                Here you can manage your fursuit badges.
            </p>
        </div>
        <PaymentInfoWidget />
        <div class="flex justify-end">
            <Button
                @click="router.visit(route('badges.create'))"
                size="small"
                v-if="canCreate"
                icon="pi pi-plus"
                class="w-full sm:w-auto"
                label="Order another Badge"
            ></Button>
        </div>
        <div class="divide-gray-100 divide-y">
            <BadgeListItem
                @click="router.visit(route('badges.edit',{badge: badge.id}))"
                v-for="badge in badges"
                :key="badge.id"
                :badge="badge"
            ></BadgeListItem>
        </div>
    </div>

</template>

<style scoped>

</style>
