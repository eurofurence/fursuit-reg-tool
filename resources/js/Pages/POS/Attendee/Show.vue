<script setup>
import POSLayout from "@/Layouts/POSLayout.vue";

import TabView from 'primevue/tabview';
import TabPanel from 'primevue/tabpanel';
import BadgesTable from "@/Components/POS/Attendee/BadgesTable.vue";
import FursuitTable from "@/Components/POS/Attendee/FursuitTable.vue";
import WalletTransactionsTable from "@/Components/POS/Attendee/WalletTransactionsTable.vue";
import CheckoutsTable from "@/Components/POS/Attendee/CheckoutsTable.vue"
import DashboardButton from "@/Components/POS/DashboardButton.vue";
import {computed, ref, watchEffect, onMounted, onUnmounted} from "vue";
import { usePosKeyboard } from '@/composables/usePosKeyboard';
import {router} from "@inertiajs/vue3";

// Handle global POS keyboard shortcuts
function handlePosShortcuts() {
    window.addEventListener('pos-shortcut-payment', () => {
        startPayment();
    });
    window.addEventListener('pos-shortcut-handout', () => {
        showHandoutConfirmModal.value = true;
    });
    window.addEventListener('pos-shortcut-confirm', () => {
        // Confirm print dialog
        if (showPrintConfirmModal.value) {
            printBadge();
        }
        // Confirm handout dialog
        if (showHandoutConfirmModal.value) {
            bulkHandout();
        }
    });
}

onMounted(() => {
    handlePosShortcuts();
});

onUnmounted(() => {
    window.removeEventListener('pos-shortcut-payment', startPayment);
    window.removeEventListener('pos-shortcut-handout', () => { showHandoutConfirmModal.value = true; });
    window.removeEventListener('pos-shortcut-confirm', () => {});
});
import ConfirmModal from "@/Components/POS/ConfirmModal.vue";
import {useForm} from "laravel-precognition-vue-inertia";
import {formatEuroFromCents} from "@/helpers.js";
import Button from 'primevue/button';
import Card from 'primevue/card';
import Divider from 'primevue/divider';

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    badges: Array,
    fursuits: Array,
    transactions: Array,
    checkouts: Array,
    attendee: Object,
    pastEventBadges: Array,
    currentEvent: Object,
    eventUser: Object,
});

const selectedBadges = ref([]);
const badgeIdToPrint = ref(null);
const showPrintConfirmModal = ref(false);
const showHandoutConfirmModal = ref(false);
const showPastEvents = ref(false);

const badgesReadyForHandout = computed(() => {
    return props.badges.filter(badge => badge.status_fulfillment === 'ready_for_pickup').length;
});

watchEffect(() => {
    if (badgeIdToPrint.value) {
        showPrintConfirmModal.value = true;
    }
});

function printBadge() {
    const form = useForm('POST', route('pos.badges.print', {badge: badgeIdToPrint.value}), {});
    form.submit();
    showPrintConfirmModal.value = false;
    badgeIdToPrint.value = null;
}

function bulkHandout() {
    useForm('POST', route('pos.badges.handout.bulk'), {
        badge_ids: (selectedBadges.value.length > 0) ? selectedBadges.value.map(badge => badge.id) : props.badges.filter(badge => badge.status_fulfillment === 'ready_for_pickup').map(badge => badge.id)
    }).submit();
    showHandoutConfirmModal.value = false;
}


function startPayment() {
    router.post(route('pos.checkout.store', {
        user_id: props.attendee.id,
        badge_ids: selectedBadges.value.map(badge => badge.id)
    }));
}

// Use keyboard composable with custom handlers (must be after refs and functions are defined)
usePosKeyboard({
    // Handle Backspace to go back to attendee search
    onBackspace: () => {
        router.visit('/pos/attendees/lookup');
    },
    // Override divide key (/) to start payment
    onNumpadDivide: () => {
        startPayment();
    },
    // Override multiply key (*) to show handout modal
    onNumpadMultiply: () => {
        showHandoutConfirmModal.value = true;
    }
});

</script>

<template>
    <div class="w-full flex-1">
    <div>
        <ConfirmModal
            title="Confirm"
            message="Are you sure you want to print this badge?"
            :show="showPrintConfirmModal"
            @confirm="printBadge()"
            @cancel="showPrintConfirmModal = false; badgeIdToPrint = null"
        />
        <ConfirmModal
            :title="((selectedBadges.length === 0) ? badgesReadyForHandout : (Math.min(selectedBadges.length, badgesReadyForHandout))) + ' Badges marked for Handout'"
            message="This will try to mark badges for handout. Are you sure?"
            :show="showHandoutConfirmModal"
            @confirm="bulkHandout()"
            @cancel="showHandoutConfirmModal = false"
        />
    </div>
    <div class="flex flex-col flex-1 min-h-full gap-4">
        <div>
            <h1 class="text-2xl font-bold mb-4">
                {{ attendee.name }}<span class="text-gray-400">#</span>{{ eventUser?.attendee_id || 'N/A' }}
            </h1>
            <div class="grid grid-cols-4 gap-4">
                <DashboardButton label="Pay" :subtitle="formatEuroFromCents(attendee.wallet.balance *-1) +' Unpaid'" icon="pi pi-money-bill" @click="startPayment()"></DashboardButton>
                <DashboardButton label="Handout" :subtitle="badgesReadyForHandout + ' to handout'" icon="pi pi-th-large" @click="showHandoutConfirmModal = true"></DashboardButton>
                <DashboardButton label="Cancel" icon="pi pi-arrow-circle-left" :route="route('pos.dashboard')"></DashboardButton>
                <DashboardButton label="Next" icon="pi pi-arrow-circle-right" :route="route('pos.attendee.lookup')"></DashboardButton>
            </div>
        </div>
        <div class="py-3 rounded-lg bg-white">
            <TabView>
                <TabPanel header="Badges">
                    <!-- Current Event Badges -->
                    <div class="mb-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">
                                {{ currentEvent.name }} Badges
                            </h3>
                            <div class="text-sm text-gray-500">
                                {{ badges.length }} badge(s)
                            </div>
                        </div>
                        <BadgesTable
                            :badges="badges"
                            :attendee="attendee"
                            @update:selected-badges="args => selectedBadges = args"
                            @print-badge="args => badgeIdToPrint = args"
                        />
                    </div>

                    <!-- Past Events Section -->
                    <div v-if="pastEventBadges.length > 0">
                        <Divider />
                        <div class="mt-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-gray-800">Past Events</h3>
                                <Button
                                    :label="showPastEvents ? 'Hide Past Events' : 'Show Past Events'"
                                    :icon="showPastEvents ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                                    severity="secondary"
                                    @click="showPastEvents = !showPastEvents"
                                    class="p-button-sm"
                                />
                            </div>

                            <!-- Past Event Badges Tables -->
                            <div v-if="showPastEvents" class="space-y-4">
                                <div v-for="eventData in pastEventBadges" :key="eventData.event.id">
                                    <h4 class="text-md font-medium text-gray-700 mb-2">{{ eventData.event.name }}</h4>
                                    <BadgesTable
                                        :badges="eventData.badges"
                                        :attendee="attendee"
                                        :readonly="true"
                                        @print-badge="args => badgeIdToPrint = args"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </TabPanel>
                <TabPanel header="Fursuit">
                   <FursuitTable :fursuits="fursuits" :attendee="attendee" />
                </TabPanel>
                <TabPanel header="Transactions">
                    <WalletTransactionsTable :transactions="transactions" />
                </TabPanel>
                <TabPanel header="Checkouts">
                    <CheckoutsTable :checkouts="checkouts" />
                </TabPanel>
            </TabView>
        </div>
    </div>
    </div>
</template>
