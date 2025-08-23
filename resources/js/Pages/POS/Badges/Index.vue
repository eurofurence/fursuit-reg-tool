<script setup>
import POSLayout from "@/Layouts/POSLayout.vue";
import TabView from 'primevue/tabview';
import TabPanel from 'primevue/tabpanel';
import DataTable from "primevue/datatable";
import Column from "primevue/column";
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Paginator from 'primevue/paginator';
import ConfirmModal from "@/Components/POS/ConfirmModal.vue";
import { ref, computed, watchEffect, onMounted, onUnmounted } from "vue";
import { useForm } from "laravel-precognition-vue-inertia";
import { formatEuroFromCents } from "@/helpers.js";
import dayjs from "dayjs";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    badges: Array,
    pagination: Object,
    currentEvent: Object,
});

// Reactive data
const badgeIdToPrint = ref(null);
const showPrintConfirmModal = ref(false);
const showPrintAllConfirmModal = ref(false);
const currentTab = ref('unprinted');
const currentPage = ref(0);
const rowsPerPage = ref(20);

// Computed data based on current tab
const filteredBadges = computed(() => {
    switch (currentTab.value) {
        case 'unprinted':
            return props.badges.filter(badge => badge.status_fulfillment === 'pending');
        case 'printed':
            return props.badges.filter(badge => ['printed', 'ready_for_pickup', 'picked_up'].includes(badge.status_fulfillment));
        case 'all':
        default:
            return props.badges;
    }
});

// Paginated badges
const paginatedBadges = computed(() => {
    const start = currentPage.value * rowsPerPage.value;
    const end = start + rowsPerPage.value;
    return filteredBadges.value.slice(start, end);
});

const totalRecords = computed(() => filteredBadges.value.length);

const printAllBadges = computed(() => {
    return filteredBadges.value.filter(badge => badge.status_fulfillment === 'pending');
});

// Handle tab change
function onTabChange(event) {
    currentTab.value = ['unprinted', 'printed', 'all'][event.index];
    currentPage.value = 0; // Reset to first page when changing tabs
}

// Handle pagination
function onPageChange(event) {
    currentPage.value = event.page;
}

// Print single badge
function printBadge() {
    const form = useForm('POST', route('pos.badges.print', { badge: badgeIdToPrint.value }), {});
    form.submit();
    showPrintConfirmModal.value = false;
    badgeIdToPrint.value = null;
}

// Print all unprinted badges
function printAllBadgesAction() {
    const badgeIds = printAllBadges.value.map(badge => badge.id);
    const form = useForm('POST', route('pos.badges.print.bulk'), { badge_ids: badgeIds });
    form.submit();
    showPrintAllConfirmModal.value = false;
}

// Trigger print confirmation
function triggerPrint(badgeId) {
    badgeIdToPrint.value = badgeId;
    showPrintConfirmModal.value = true;
}

// Handle keyboard shortcuts
function handlePosShortcuts() {
    window.addEventListener('pos-shortcut-confirm', () => {
        if (showPrintConfirmModal.value) {
            printBadge();
        }
        if (showPrintAllConfirmModal.value) {
            printAllBadgesAction();
        }
    });
}

onMounted(() => {
    handlePosShortcuts();
});

onUnmounted(() => {
    window.removeEventListener('pos-shortcut-confirm', () => {});
});

watchEffect(() => {
    if (badgeIdToPrint.value) {
        showPrintConfirmModal.value = true;
    }
});
</script>

<template>
    <div>
        <ConfirmModal
            title="Confirm Print"
            message="Are you sure you want to print this badge?"
            :show="showPrintConfirmModal"
            @confirm="printBadge()"
            @cancel="showPrintConfirmModal = false; badgeIdToPrint = null"
        />
        <ConfirmModal
            :title="`Print All ${printAllBadges.length} Unprinted Badges`"
            message="This will print all unprinted badges. Are you sure?"
            :show="showPrintAllConfirmModal"
            @confirm="printAllBadgesAction()"
            @cancel="showPrintAllConfirmModal = false"
        />
    </div>

    <div class="grid grid-cols-1 gap-4 p-4">
        <div class="bg-white p-4 mb-4 rounded-lg shadow">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Badge Management</h1>
                    <div class="text-sm text-gray-500 mt-1">
                        Current Event: {{ currentEvent.name }}
                    </div>
                </div>
                <div class="flex gap-2">
                    <Button 
                        v-if="printAllBadges.length > 0"
                        label="Print All Unprinted"
                        icon="pi pi-print"
                        @click="showPrintAllConfirmModal = true"
                        class="p-button-warning"
                    />
                    <Button 
                        label="Back to Dashboard"
                        icon="pi pi-arrow-left"
                        severity="secondary"
                        @click="$inertia.visit(route('pos.dashboard'))"
                    />
                </div>
            </div>
        </div>

        <div class="py-3 rounded-lg bg-white">
            <TabView @tab-change="onTabChange">
                <TabPanel header="Unprinted">
                    <div class="mb-4 flex justify-between items-center">
                        <div class="text-sm text-gray-600">
                            {{ filteredBadges.length }} unprinted badge(s)
                        </div>
                        <Button 
                            v-if="printAllBadges.length > 0"
                            label="Print All"
                            icon="pi pi-print"
                            size="small"
                            @click="showPrintAllConfirmModal = true"
                        />
                    </div>
                </TabPanel>
                <TabPanel header="Printed">
                    <div class="mb-4 text-sm text-gray-600">
                        {{ filteredBadges.length }} printed badge(s)
                    </div>
                </TabPanel>
                <TabPanel header="All">
                    <div class="mb-4 text-sm text-gray-600">
                        {{ filteredBadges.length }} total badge(s)
                    </div>
                </TabPanel>
            </TabView>

            <!-- Badges Table -->
            <DataTable 
                :value="paginatedBadges" 
                class="-m-5"
                tableStyle="min-width: 50rem"
                dataKey="id"
            >
                <Column field="custom_id" header="Badge ID" sortable></Column>
                <Column field="fursuit.name" header="Fursuit" sortable>
                    <template #body="slotProps">
                        <div class="font-medium">{{ slotProps.data.fursuit.name }}</div>
                        <div class="text-sm text-gray-500">{{ slotProps.data.fursuit.species.name }}</div>
                    </template>
                </Column>
                <Column field="fursuit.user.name" header="Owner" sortable>
                    <template #body="slotProps">
                        <div>{{ slotProps.data.fursuit.user.name }}</div>
                        <div class="text-sm text-gray-500">
                            #{{ slotProps.data.fursuit.user.eventUsers?.find(eu => eu.event_id === currentEvent.id)?.attendee_id || 'N/A' }}
                        </div>
                    </template>
                </Column>
                <Column field="status_fulfillment" header="Status">
                    <template #body="slotProps">
                        <div class="flex flex-col gap-1">
                            <Tag 
                                :severity="slotProps.data.status_fulfillment === 'pending' ? 'warning' : 
                                          slotProps.data.status_fulfillment === 'printed' ? 'info' :
                                          slotProps.data.status_fulfillment === 'ready_for_pickup' ? 'success' :
                                          slotProps.data.status_fulfillment === 'picked_up' ? 'secondary' : 'info'"
                                :value="slotProps.data.status_fulfillment === 'pending' ? 'Pending' :
                                       slotProps.data.status_fulfillment === 'printed' ? 'Printed' :
                                       slotProps.data.status_fulfillment === 'ready_for_pickup' ? 'Ready for Pickup' :
                                       slotProps.data.status_fulfillment === 'picked_up' ? 'Picked Up' :
                                       slotProps.data.status_fulfillment"
                            />
                            <div class="flex items-center gap-2 text-sm">
                                <div 
                                    :class="slotProps.data.status_payment === 'unpaid' ? 'w-2 h-2 bg-red-500 rounded-full' : 'w-2 h-2 bg-green-500 rounded-full'"
                                />
                                {{ formatEuroFromCents(slotProps.data.total) }}
                            </div>
                        </div>
                    </template>
                </Column>
                <Column field="printed_at" header="Printed At">
                    <template #body="slotProps">
                        {{ slotProps.data.printed_at ? dayjs(slotProps.data.printed_at).format('DD.MM.YY HH:mm') : '-' }}
                    </template>
                </Column>
                <Column header="Actions">
                    <template #body="slotProps">
                        <div class="flex gap-2">
                            <Button 
                                :label="slotProps.data.status_fulfillment === 'pending' ? 'Print' : 'Reprint'"
                                :severity="slotProps.data.status_fulfillment === 'pending' ? 'primary' : 'secondary'"
                                size="small"
                                icon="pi pi-print"
                                @click="triggerPrint(slotProps.data.id)"
                            />
                        </div>
                    </template>
                </Column>
            </DataTable>

            <!-- Pagination -->
            <div class="mt-4" v-if="totalRecords > rowsPerPage">
                <Paginator 
                    :first="currentPage * rowsPerPage"
                    :rows="rowsPerPage"
                    :totalRecords="totalRecords"
                    @page="onPageChange"
                    :rowsPerPageOptions="[10, 20, 50, 100]"
                />
            </div>
        </div>
    </div>
</template>