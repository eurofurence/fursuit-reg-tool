<script setup>

import {Head, router} from "@inertiajs/vue3";
import Layout from "@/Layouts/Layout.vue";
import BadgeListItem from "@/Components/BadgeListItem.vue";
import Button from "primevue/button"
import Message from "primevue/message"
import PaymentInfoWidget from "@/Components/PaymentInfoWidget.vue";
import Card from "primevue/card";
import DataTable from "primevue/datatable";
import Column from "primevue/column";
import Tag from "primevue/tag";
import { formatEuroFromCents } from "@/helpers.js";

defineOptions({
    layout: Layout
})
const props = defineProps({
    badges: Array,
    badgeCount: Number,
    unpickedBadges: Array,
    canCreate: Boolean,
    hasFreeBadge: Boolean,
    freeBadgeCopies: Number,
    event: Object
});

function canEditBadge(badge) {
    return badge.canEdit;
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
</script>

<template>
    <Head title="Manage your Fursuit Badges"/>
    <div class="pt-8 px-6 xl:px-0 max-w-screen-lg mx-auto">
        <!-- Header Section -->
        <div class="mb-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl md:text-3xl font-semibold font-main">Your Fursuit Badges</h1>
                    <p class="text-gray-600">
                        Total badges: <span class="font-semibold">{{ badgeCount }}</span>
                        <span v-if="event && event.name"> • {{ event.name }}</span>
                    </p>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-2">
                    <!-- Free Badge Button -->
                    <Button
                        v-if="hasFreeBadge && event && event.allowsOrders"
                        @click="router.visit(route('badges.create'))"
                        size="small"
                        severity="success"
                        icon="pi pi-gift"
                        class="w-full sm:w-auto"
                        :label="`Add Free Badge${freeBadgeCopies > 0 ? ` (+${freeBadgeCopies} copies)` : ''}`"
                    />
                    
                    <!-- Purchase Badge Button -->
                    <Button
                        v-else-if="canCreate && event && event.allowsOrders"
                        @click="router.visit(route('badges.create'))"
                        size="small"
                        icon="pi pi-plus"
                        class="w-full sm:w-auto"
                        label="Purchase Badge"
                    />
                    
                    <!-- Orders Closed Message -->
                    <Message 
                        v-else-if="event && !event.allowsOrders"
                        severity="info"
                        :closable="false"
                        class="mb-0"
                    >
                        Badge ordering is currently closed
                    </Message>
                </div>
            </div>
        </div>

        <PaymentInfoWidget />

        <!-- Badges Table -->
        <Card v-if="badges.length > 0" class="mt-6">
            <template #content>
                <DataTable 
                    :value="badges" 
                    stripedRows 
                    class="p-datatable-sm"
                    :rowHover="true"
                >
                    <!-- Image Column -->
                    <Column header="Image" style="width: 120px;">
                        <template #body="slotProps">
                            <img 
                                :src="slotProps.data.fursuit.image_url" 
                                :alt="`${slotProps.data.fursuit.name} badge`"
                                class="w-16 h-16 object-cover rounded-lg border"
                            />
                        </template>
                    </Column>

                    <!-- Name & Species Column -->
                    <Column header="Fursuit Details">
                        <template #body="slotProps">
                            <div>
                                <div class="font-semibold">{{ slotProps.data.fursuit.name }}</div>
                                <div class="text-sm text-gray-600">{{ slotProps.data.fursuit.species.name }}</div>
                                <div v-if="slotProps.data.extra_copy" class="mt-1">
                                    <Tag severity="info" value="Extra Copy" size="small" />
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Status Column -->
                    <Column header="Status">
                        <template #body="slotProps">
                            <div class="flex flex-col gap-1">
                                <!-- Show fursuit status if pending -->
                                <Tag 
                                    v-if="slotProps.data.status_fulfillment === 'pending'"
                                    :severity="getFursuitSeverity(slotProps.data.fursuit.status)"
                                    :value="getFursuitStatusName(slotProps.data.fursuit.status)"
                                    size="small"
                                />
                                
                                <!-- Show badge status if fursuit approved -->
                                <Tag 
                                    v-else-if="slotProps.data.fursuit.status === 'approved'"
                                    :severity="getBadgeSeverity(slotProps.data.status_fulfillment)"
                                    :value="getBadgeStatusName(slotProps.data.status_fulfillment)"
                                    size="small"
                                />
                                
                                <!-- Payment Status -->
                                <Tag 
                                    v-if="slotProps.data.status_payment === 'unpaid'"
                                    severity="danger"
                                    value="Not Paid"
                                    size="small"
                                />
                            </div>
                        </template>
                    </Column>

                    <!-- Price Column -->
                    <Column header="Price" style="width: 100px;">
                        <template #body="slotProps">
                            <div class="text-right">
                                <div class="font-semibold">{{ formatEuroFromCents(slotProps.data.total) }}</div>
                            </div>
                        </template>
                    </Column>

                    <!-- Actions Column -->
                    <Column header="Actions" style="width: 100px;">
                        <template #body="slotProps">
                            <Button
                                v-if="canEditBadge(slotProps.data)"
                                @click="router.visit(route('badges.edit', {badge: slotProps.data.id}))"
                                icon="pi pi-pencil"
                                size="small"
                                text
                                severity="secondary"
                                v-tooltip.top="'Edit Badge'"
                            />
                            <Tag
                                v-else
                                severity="secondary"
                                value="Cannot Edit"
                                size="small"
                            />
                        </template>
                    </Column>
                </DataTable>
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
                        :label="hasFreeBadge ? 'Create Free Badge' : 'Purchase Badge'"
                        :severity="hasFreeBadge ? 'success' : 'primary'"
                    />
                </div>
            </template>
        </Card>

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
                    Please come to our desk in the fursuit lounge after the 2nd con day to collect them.
                </Message>
                
                <DataTable 
                    :value="unpickedBadges" 
                    stripedRows 
                    class="p-datatable-sm"
                    :rowHover="false"
                >
                    <!-- Image Column -->
                    <Column header="Image" style="width: 120px;">
                        <template #body="slotProps">
                            <img 
                                :src="slotProps.data.fursuit.image_url" 
                                :alt="`${slotProps.data.fursuit.name} badge`"
                                class="w-16 h-16 object-cover rounded-lg border opacity-75"
                            />
                        </template>
                    </Column>

                    <!-- Name & Species Column -->
                    <Column header="Fursuit Details">
                        <template #body="slotProps">
                            <div>
                                <div class="font-semibold">{{ slotProps.data.fursuit.name }}</div>
                                <div class="text-sm text-gray-600">{{ slotProps.data.fursuit.species.name }}</div>
                                <div class="text-sm text-gray-500 mt-1">
                                    {{ slotProps.data.fursuit.event.name }}
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Status Column -->
                    <Column header="Status">
                        <template #body="slotProps">
                            <Tag 
                                :severity="getBadgeSeverity(slotProps.data.status_fulfillment)"
                                :value="getBadgeStatusName(slotProps.data.status_fulfillment)"
                                size="small"
                            />
                        </template>
                    </Column>

                    <!-- Price Column -->
                    <Column header="Price" style="width: 100px;">
                        <template #body="slotProps">
                            <div class="text-right">
                                <div class="font-semibold">{{ formatEuroFromCents(slotProps.data.total) }}</div>
                            </div>
                        </template>
                    </Column>

                    <!-- Actions Column -->
                    <Column header="" style="width: 100px;">
                        <template #body="slotProps">
                            <Tag
                                severity="secondary"
                                value="Awaiting Pickup"
                                size="small"
                            />
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>
    </div>
</template>

<style scoped>

</style>
