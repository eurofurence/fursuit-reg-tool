<script setup>
import POSLayout from "@/Layouts/POSLayout.vue";
import ConfirmModal from "@/Components/POS/ConfirmModal.vue";
import { posDialog } from "@/Components/POS/posDialog.js";
import UiDialog from "@/Components/UI/UiDialog.vue";
import UiTag from "@/Components/UI/UiTag.vue";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/Components/UI/tabs";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/UI/table";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/Components/UI/select";
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationNext,
    PaginationPrevious,
} from "@/Components/UI/pagination";
import { ArrowDown, ArrowLeft, ArrowUp, ArrowUpDown, Info, Printer } from "@lucide/vue";
import {ref, computed, onMounted, onUnmounted} from "vue";
import {useForm} from "@inertiajs/vue3";
import {router} from "@inertiajs/vue3";
import {formatEuroFromCents} from "@/helpers.js";
import dayjs from "dayjs";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    badges: Object, // Laravel paginated response
    pagination: Object,
    currentEvent: Object,
    printers: Array,
    tabCounts: Object,
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
const currentPageFromServer = computed(() => props.badges?.current_page || 1);

// Client-side sort of the current page, cycling asc then desc per column.
const sortField = ref(null);
const sortOrder = ref(1);

function toggleSort(field) {
    sortOrder.value = sortField.value === field ? -sortOrder.value : 1;
    sortField.value = field;
}

const sortedBadges = computed(() => {
    if (!sortField.value) {
        return badges.value;
    }

    const field = sortField.value;

    return [...badges.value].sort((a, b) =>
        String(a[field] ?? '').localeCompare(String(b[field] ?? ''), undefined, { numeric: true }) * sortOrder.value
    );
});

const sortableColumns = [
    { field: 'custom_id', header: 'Badge ID' },
    { field: 'fursuit_name', header: 'Fursuit' },
    { field: 'owner_name', header: 'Owner' },
];

const tabs = computed(() => [
    { value: 'unprinted', label: `Unprinted (${props.tabCounts?.unprinted || 0})`, text: 'unprinted badge(s) displayed' },
    { value: 'processing', label: `Processing (${props.tabCounts?.processing || 0})`, text: 'processing badge(s) displayed' },
    { value: 'printed', label: `Printed (${props.tabCounts?.printed || 0})`, text: 'printed badge(s) displayed' },
    { value: 'all', label: `All (${props.tabCounts?.all || 0})`, text: 'total badge(s) displayed' },
]);

// Check if there are any unprinted badges (used to show/hide the Print All button)
const hasUnprintedBadges = computed(() => {
    return (props.tabCounts?.unprinted || 0) > 0;
});

// Handle tab change with Inertia navigation
function onTabChange(selectedTab) {
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
function onPageChange(page) {
    router.get(route('pos.badges.index'), {
        tab: currentTab.value,
        page,
    }, {
        preserveState: true,
        preserveScroll: true
    });
}

// Print single badge
function printBadge() {
    const form = useForm('post', route('pos.badges.print', {badge: badgeIdToPrint.value}), {});
    form.submit();
    showPrintConfirmModal.value = false;
    badgeIdToPrint.value = null;
}

// Print all unprinted badges
function printAllBadgesAction() {
    // Don't send badge_ids - let backend determine which badges to print
    const formData = {};

    if (selectedPrinter.value) {
        formData.printer_id = selectedPrinter.value;
    }

    const form = useForm('post', route('pos.badges.print.bulk'), formData);
    form.submit();
    showPrintAllConfirmModal.value = false;
    selectedPrinter.value = null;
}

// Trigger print confirmation
function triggerPrint(badgeId) {
    badgeIdToPrint.value = badgeId;
    showPrintConfirmModal.value = true;
}

// preventDefault() marks the Enter as consumed, so it cannot also activate the
// Print button that regains focus once the dialog closes.
function onConfirmShortcut(event) {
    if (showPrintConfirmModal.value) {
        event.preventDefault();
        printBadge();

        return;
    }

    if (showPrintAllConfirmModal.value) {
        event.preventDefault();
        printAllBadgesAction();
    }
}

onMounted(() => {
    window.addEventListener('pos-shortcut-confirm', onConfirmShortcut);
});

onUnmounted(() => {
    window.removeEventListener('pos-shortcut-confirm', onConfirmShortcut);
});
</script>

<template>
    <div class="w-full flex-1">
        <div class="w-full">
            <ConfirmModal
                title="Confirm Print"
                message="Are you sure you want to print this badge?"
                :show="showPrintConfirmModal"
                @confirm="printBadge()"
                @cancel="showPrintConfirmModal = false; badgeIdToPrint = null"
            />

            <!-- Print All Badges Dialog -->
            <UiDialog
                v-model:visible="showPrintAllConfirmModal"
                v-bind="posDialog"
                modal
                header="Print Unprinted Badges"
                width="30rem"
            >
                <div class="space-y-4">
                    <p class="text-sm text-pos-muted">
                        This will print up to 50 unprinted badges, starting with the lowest attendee IDs.
                        Optionally select a specific printer:
                    </p>
                    <div class="bg-pos-accent/10 border border-pos-accent/30 rounded-pos p-3">
                        <div class="flex items-start">
                            <Info class="size-4 shrink-0 text-pos-accent mt-0.5 mr-2" aria-hidden="true" />
                            <div class="text-sm text-pos-accent">
                                <strong>Note:</strong> Maximum of <span class="pos-num font-bold">50</span> badges will be printed per batch.
                                Badges are selected by lowest attendee ID first.
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="pos-label block">Printer</label>
                        <Select v-model="selectedPrinter">
                            <SelectTrigger class="w-full text-base data-[size=default]:h-12">
                                <SelectValue placeholder="Use default assignment" />
                            </SelectTrigger>
                            <SelectContent class="pos-surface z-1102">
                                <SelectItem
                                    v-for="printer in props.printers"
                                    :key="printer.id"
                                    :value="printer.id"
                                    class="min-h-pos-touch text-base"
                                >
                                    {{ printer.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-pos-muted">Leave empty for automatic printer assignment</p>
                    </div>
                </div>

                <template #footer>
                    <button
                        type="button"
                        class="pos-btn"
                        @click="showPrintAllConfirmModal = false; selectedPrinter = null"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="pos-btn pos-btn--primary"
                        @click="printAllBadgesAction()"
                    >
                        <Printer class="size-4" aria-hidden="true" />
                        Print All <span class="pos-kcap">Enter</span>
                    </button>
                </template>
            </UiDialog>
        </div>

        <div class="flex flex-col gap-2">
            <div class="pos-card mb-2">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold">Badge Management</h1>
                        <div class="text-sm text-pos-muted mt-1">
                            Current Event: {{ currentEvent.name }}
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button
                            v-if="hasUnprintedBadges"
                            type="button"
                            class="pos-btn pos-btn--primary"
                            @click="showPrintAllConfirmModal = true"
                        >
                            <Printer class="size-4" aria-hidden="true" />
                            Print All Unprinted
                        </button>
                        <button
                            type="button"
                            class="pos-btn"
                            @click="router.visit(route('pos.dashboard'))"
                        >
                            <ArrowLeft class="size-4" aria-hidden="true" />
                            Back to Dashboard
                        </button>
                    </div>
                </div>
            </div>

            <div class="pos-card py-3">
                <Tabs :model-value="currentTab" activation-mode="manual" @update:model-value="onTabChange">
                    <TabsList class="h-12">
                        <TabsTrigger v-for="tab in tabs" :key="tab.value" :value="tab.value" class="px-4 text-base">
                            {{ tab.label }}
                        </TabsTrigger>
                    </TabsList>
                    <TabsContent v-for="tab in tabs" :key="tab.value" :value="tab.value">
                        <div class="mb-4 text-sm text-pos-muted">
                            {{ totalRecords }} {{ tab.text }}
                        </div>
                    </TabsContent>
                </Tabs>

                <Table class="mt-2 min-w-[50rem]">
                    <TableHeader>
                        <TableRow>
                            <TableHead v-for="column in sortableColumns" :key="column.field" :aria-sort="sortField === column.field ? (sortOrder === 1 ? 'ascending' : 'descending') : 'none'">
                                <button type="button" class="inline-flex min-h-pos-touch items-center gap-2 font-medium" @click="toggleSort(column.field)">
                                    {{ column.header }}
                                    <component
                                        :is="sortField !== column.field ? ArrowUpDown : (sortOrder === 1 ? ArrowUp : ArrowDown)"
                                        class="size-4 text-pos-muted"
                                        aria-hidden="true"
                                    />
                                </button>
                            </TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Printed At</TableHead>
                            <TableHead>Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="badge in sortedBadges" :key="badge.id">
                            <TableCell>{{ badge.custom_id }}</TableCell>
                            <TableCell>
                                <div class="font-medium">{{ badge.fursuit_name }}</div>
                                <div class="text-sm text-pos-muted">{{ badge.species_name }}</div>
                            </TableCell>
                            <TableCell>{{ badge.owner_name }}</TableCell>
                            <TableCell>
                                <div class="flex flex-col items-start gap-1">
                                    <UiTag
                                        :severity="badge.status_fulfillment === 'pending' ? 'warning' :
                                              badge.status_fulfillment === 'processing' ? 'info' :
                                              badge.status_fulfillment === 'ready_for_pickup' ? 'success' :
                                              badge.status_fulfillment === 'picked_up' ? 'secondary' : 'info'"
                                        :value="badge.status_fulfillment === 'pending' ? 'Pending' :
                                           badge.status_fulfillment === 'processing' ? 'Processing' :
                                           badge.status_fulfillment === 'ready_for_pickup' ? 'Ready for Pickup' :
                                           badge.status_fulfillment === 'picked_up' ? 'Picked Up' :
                                           badge.status_fulfillment"
                                    />
                                    <div class="flex items-center gap-2 text-sm">
                                        <div
                                            :class="badge.status_payment === 'unpaid' ? 'w-2 h-2 bg-pos-bad rounded-full' : 'w-2 h-2 bg-pos-good rounded-full'"
                                        />
                                        {{ formatEuroFromCents(badge.total) }}
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell>
                                {{ badge.printed_at ? dayjs(badge.printed_at).format('DD.MM.YY HH:mm') : '-' }}
                            </TableCell>
                            <TableCell>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="pos-btn pos-btn--sm"
                                        :class="badge.status_fulfillment === 'pending' ? 'pos-btn--primary' : ''"
                                        @click="triggerPrint(badge.id)"
                                    >
                                        <Printer class="size-4" aria-hidden="true" />
                                        {{ badge.status_fulfillment === 'pending' ? 'Print' : 'Reprint' }}
                                    </button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <div class="mt-4" v-if="totalRecords > 0">
                    <Pagination
                        v-slot="{ page }"
                        :page="currentPageFromServer"
                        :items-per-page="50"
                        :total="totalRecords"
                        @update:page="onPageChange"
                    >
                        <PaginationContent v-slot="{ items }">
                            <PaginationPrevious class="h-12" />
                            <template v-for="(item, index) in items" :key="index">
                                <PaginationItem v-if="item.type === 'page'" :value="item.value" :is-active="item.value === page" class="size-12 text-base">
                                    {{ item.value }}
                                </PaginationItem>
                                <PaginationEllipsis v-else />
                            </template>
                            <PaginationNext class="h-12" />
                        </PaginationContent>
                    </Pagination>
                </div>
            </div>
        </div>
    </div>
</template>
