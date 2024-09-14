<script setup>
import POSLayout from "@/Layouts/POSLayout.vue";

import TabView from 'primevue/tabview';
import TabPanel from 'primevue/tabpanel';
import BadgesTable from "@/Components/POS/Attendee/BadgesTable.vue";
import FursuitTable from "@/Components/POS/Attendee/FursuitTable.vue";
import WalletTransactionsTable from "@/Components/POS/Attendee/WalletTransactionsTable.vue";
import CheckoutsTable from "@/Components/POS/Attendee/CheckoutsTable.vue"
import DashboardButton from "@/Components/POS/DashboardButton.vue";
import {computed, ref, watch, watchEffect} from "vue";
import ConfirmModal from "@/Components/POS/ConfirmModal.vue";
import {useForm} from "laravel-precognition-vue-inertia";
import {formatEuroFromCents} from "@/helpers.js";
import {router} from "@inertiajs/vue3";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    badges: Array,
    fursuits: Array,
    transactions: Array,
    checkouts: Array,
    attendee: Object,
});

const selectedBadges = ref([]);
const badgeIdToPrint = ref(null);
const showPrintConfirmModal = ref(false);
const showHandoutConfirmModal = ref(false);

const badgesReadyForHandout = computed(() => {
    return props.badges.filter(badge => badge.status === 'ready_for_pickup').length;
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
        badge_ids: (selectedBadges.value.length) ? selectedBadges.value.map(badge => badge.id) : props.badges.filter(badge => badge.status === 'ready_for_pickup').map(badge => badge.id)
    }).submit();
    showHandoutConfirmModal.value = false;
}

function handoutBadges() {
    showHandoutConfirmModal.value = false;
}

function startPayment() {
    router.post(route('pos.checkout.store', {
        user_id: props.attendee.id,
        badge_ids: selectedBadges.value.map(badge => badge.id)
    }));
}

</script>

<template>
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
    <div class="grid grid-cols-1 gap-4 p-4">
        <div>
            <div class="bg-white p-4 mb-4 rounded-lg shadow">
                <h1 class="text-2xl font-bold">
                    <!-- <span class="text-gray-500">Attendee</span>  -->
                    {{ attendee.name }}<span class="text-gray-400">#</span>{{ attendee.attendee_id }}
                </h1>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <DashboardButton label="Pay" :subtitle="formatEuroFromCents(attendee.wallet.balance *-1) +' Unpaid'" icon="pi pi-money-bill" @click="startPayment()"></DashboardButton>
                <DashboardButton label="Handout" :subtitle="badgesReadyForHandout + ' to handout'" icon="pi pi-th-large" @click="showHandoutConfirmModal = true"></DashboardButton>
                <DashboardButton label="Cancel" icon="pi pi-arrow-circle-left" :route="route('pos.dashboard')"></DashboardButton>
            </div>
        </div>
        <div class="py-3 rounded-lg bg-white">
            <TabView>
                <TabPanel header="Badges">
                  <BadgesTable
                      :badges="badges"
                      :attendee="attendee"
                      @update:selected-badges="args => selectedBadges = args"
                      @print-badge="args => badgeIdToPrint = args"
                  />
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
</template>
