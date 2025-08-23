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
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import { ref, computed, watchEffect, onMounted, onUnmounted } from "vue";
import { useForm } from "laravel-precognition-vue-inertia";
import { router } from "@inertiajs/vue3";
import { formatEuroFromCents } from "@/helpers.js";
import dayjs from "dayjs";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    badges: Object, // Laravel paginated response
    pagination: Object,
    currentEvent: Object,
    printers: Array,
    filters: Object,
});

// Reactive data
const badgeIdToPrint = ref(null);
const showPrintConfirmModal = ref(false);
const showPrintAllConfirmModal = ref(false);
const selectedPrinter = ref(null);

// Get current tab from server filters
const currentTab = computed(() => props.filters?.tab || 'unprinted');

// Use server-side paginated data directly - no client-side filtering needed
const badges = computed(() => {
    return Array.isArray(props.badges?.data) ? props.badges.data : [];
});

// Get pagination info from server response
const totalRecords = computed(() => props.badges?.total || 0);
const currentPageFromServer = computed(() => (props.badges?.current_page || 1) - 1); // Convert to 0-based
const lastPage = computed(() => props.badges?.last_page || 1);

// For print all - we need to make a separate API call since we only have current page data
const printAllBadges = computed(() => {
    // Only count unprinted badges from current page view
    return badges.value.filter(badge => badge.status_fulfillment === 'pending');
});

// Handle tab change with Inertia navigation
function onTabChange(event) {
    const tabNames = ['unprinted', 'printed', 'all'];
    const selectedTab = tabNames[event.index];

    // Navigate to new URL with updated tab parameter
    router.get(route('pos.badges.index'), {
        tab: selectedTab,
        page: 1 // Reset to first page when changing tabs
    }, {
        preserveState: true,
        preserveScroll: true
    });
}

// Handle pagination with Inertia navigation
function onPageChange(event) {
    router.get(route('pos.badges.index'), {
        tab: currentTab.value,
        page: event.page + 1 // Convert from 0-based to 1-based
    }, {
        preserveState: true,
        preserveScroll: true
    });
}

// Get tab index for TabView active state
function getTabIndex() {
    const tabNames = ['unprinted', 'printed', 'all'];
    return tabNames.indexOf(currentTab.value);
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
    const formData = { badge_ids: badgeIds };

    if (selectedPrinter.value) {
        formData.printer_id = selectedPrinter.value;
    }

    const form = useForm('POST', route('pos.badges.print.bulk'), formData);
    form.submit();
    showPrintAllConfirmModal.value = false;
    selectedPrinter.value = null;
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

        <!-- Print All Badges Dialog -->
        <Dialog
            v-model:visible="showPrintAllConfirmModal"
            modal
            :header="`Print All ${printAllBadges.length} Unprinted Badges`"
            :style="{ width: '25rem' }"
        >
            <div class="space-y-4">
                <p class="text-sm text-gray-600">
                    This will print all {{ printAllBadges.length }} unprinted badges. Optionally select a specific printer:
                </p>

                <div class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700">Printer</label>
                    <Dropdown
                        v-model="selectedPrinter"
                        :options="props.printers"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Use default assignment"
                        class="w-full"
                        :clearable="true"
                    />
                    <p class="text-xs text-gray-500">Leave empty for automatic printer assignment</p>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-end space-x-2">
                    <Button
                        label="Cancel"
                        severity="secondary"
                        @click="showPrintAllConfirmModal = false; selectedPrinter = null"
                    />
                    <Button
                        label="Print All"
                        icon="pi pi-print"
                        @click="printAllBadgesAction()"
                    />
                </div>
            </template>
        </Dialog>
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
            <TabView @tab-change="onTabChange" :activeIndex="getTabIndex()">
                <TabPanel header="Unprinted">
                    <div class="mb-4 flex justify-between items-center">
                        <div class="text-sm text-gray-600">
                            {{ totalRecords }} unprinted badge(s)
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
                        {{ totalRecords }} printed badge(s)
                    </div>
                </TabPanel>
                <TabPanel header="All">
                    <div class="mb-4 text-sm text-gray-600">
                        {{ totalRecords }} total badge(s)
                    </div>
                </TabPanel>
            </TabView>

            <!-- Badges Table -->
            <DataTable
                :value="badges"
                class="-m-5"
                tableStyle="min-width: 50rem"
                dataKey="id"
            >
                <Column field="custom_id" header="Badge ID" sortable></Column>
                <Column field="fursuit_name" header="Fursuit" sortable>
                    <template #body="slotProps">
                        <div class="font-medium">{{ slotProps.data.fursuit_name }}</div>
                        <div class="text-sm text-gray-500">{{ slotProps.data.species_name }}</div>
                    </template>
                </Column>
                <Column field="owner_name" header="Owner" sortable></Column>
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
            <div class="mt-4" v-if="totalRecords > 0">
                <Paginator
                    :first="(currentPageFromServer * 50)"
                    :rows="50"
                    :totalRecords="totalRecords"
                    @page="onPageChange"
                />
            </div>
        </div>
    </div>
</template>
