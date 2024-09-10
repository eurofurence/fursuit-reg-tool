<script setup>
import POSLayout from "@/Layouts/POSLayout.vue";

import TabView from 'primevue/tabview';
import TabPanel from 'primevue/tabpanel';
import BadgesTable from "@/Components/POS/Attendee/BadgesTable.vue";
import FursuitTable from "@/Components/POS/Attendee/FursuitTable.vue";
import DashboardButton from "@/Components/POS/DashboardButton.vue";
import {computed, ref, watch, watchEffect} from "vue";
import ConfirmModal from "@/Components/POS/ConfirmModal.vue";
import {useForm} from "laravel-precognition-vue-inertia";
import {formatEuroFromCents} from "@/helpers.js";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    badges: Array,
    fursuits: Array,
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
            message="This will try to mark bades for handout. Are you sure?"
            :show="showHandoutConfirmModal"
            @confirm="bulkHandout()"
            @cancel="showHandoutConfirmModal = false"
        />
    </div>
    <div class="grid grid-cols-1 gap-4 p-4">
        <div>
            <div class="bg-white p-4 mb-4 rounded-lg shadow">
                <h1 class="text-2xl font-bold">{{ attendee.name }} # {{ attendee.attendee_id }}</h1>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <DashboardButton label="Pay" :subtitle="formatEuroFromCents(attendee.wallet.balance *-1) +' Unpaid'" icon="pi pi-money-bill" route="#"></DashboardButton>
                <DashboardButton label="Handout" :subtitle="badgesReadyForHandout + ' to handout'" icon="pi pi-th-large" @click="showHandoutConfirmModal = true"></DashboardButton>
                <DashboardButton label="Cancel" icon="pi pi-wallet" :route="route('pos.dashboard')"></DashboardButton>
            </div>
        </div>
        <div>
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
                           <p class="m-0">
                        At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores et quas molestias excepturi sint occaecati cupiditate non provident, similique sunt in culpa qui
                        officia deserunt mollitia animi, id est laborum et dolorum fuga. Et harum quidem rerum facilis est et expedita distinctio. Nam libero tempore, cum soluta nobis est eligendi optio cumque nihil impedit quo minus.
                    </p>
                </TabPanel>
            </TabView>
        </div>
    </div>
</template>
