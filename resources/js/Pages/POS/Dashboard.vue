<script setup>
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import POSLayout from "@/Layouts/POSLayout.vue";
import DashboardButton from "@/Components/POS/DashboardButton.vue";
import Card from 'primevue/card';
import { usePage } from '@inertiajs/vue3';
import { formatEuroFromCents } from '@/helpers.js';

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    stats: Object,
});

const page = usePage();
const staff = computed(() => page.props.auth.user);

// Primary actions - most commonly used
const primaryActions = [
    {
        label: "Lookup Attendee",
        subtitle: "Find attendee by ID",
        route: route('pos.attendee.lookup'),
        icon: 'pi pi-search',
        color: 'primary'
    },
    {
        label: "Cash Register",
        subtitle: "Wallet transactions",
        route: route('pos.wallet.show'),
        icon: 'pi pi-wallet',
        color: 'success'
    },
];

// Secondary actions
const secondaryActions = [
    {
        label: "Badge Management",
        subtitle: "View & print badges",
        route: route('pos.badges.index'),
        icon: 'pi pi-id-card',
        color: 'primary'
    },
    {
        label: "Print Queue",
        subtitle: "Manage print jobs",
        route: route('pos.print-queue.index'),
        icon: 'pi pi-print',
        color: 'info'
    },
    {
        label: "Printer Management",
        subtitle: "Monitor & control printers",
        route: route('pos.printers.index'),
        icon: 'pi pi-cog',
        color: 'warning'
    },
    {
        label: "Statistics",
        subtitle: "View reports",
        route: route('pos.statistics'),
        icon: 'pi pi-chart-bar',
        color: 'secondary'
    },
];

// System actions
const systemActions = [
    {
        label: "Switch User",
        subtitle: "Change staff login",
        route: route('pos.auth.user.logout'),
        icon: 'pi pi-user-edit',
        method: 'POST',
        color: 'warning'
    },
];
</script>

<template>
    <Head>
        <title>POS - Dashboard</title>
    </Head>

    <div class="w-full flex-1 flex flex-col">

        <!-- Main Actions Grid -->
        <div class="flex-1 grid gap-6">
            <!-- Primary Actions - Large Buttons -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <DashboardButton
                    v-for="action in primaryActions"
                    :key="action.label"
                    :label="action.label"
                    :subtitle="action.subtitle"
                    :icon="action.icon"
                    :route="action.route"
                    :method="action.method"
                    class="shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all duration-200"
                />
            </div>

            <!-- Secondary Actions - Medium Buttons -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <DashboardButton
                    v-for="action in secondaryActions"
                    :key="action.label"
                    :label="action.label"
                    :subtitle="action.subtitle"
                    :icon="action.icon"
                    :route="action.route"
                    :method="action.method"
                    class="shadow-md hover:shadow-lg"
                />
            </div>

            <!-- System Actions - Smaller Buttons -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <DashboardButton
                    v-for="action in systemActions"
                    :key="action.label"
                    :label="action.label"
                    :subtitle="action.subtitle"
                    :icon="action.icon"
                    :route="action.route"
                    :method="action.method"
                    class="shadow-md hover:shadow-lg bg-orange-50 hover:bg-orange-100"
                />
            </div>
        </div>

        <!-- Quick Access Footer -->
        <div class="mt-6 pt-4 border-t border-gray-200">
            <div class="flex items-center justify-between text-sm text-gray-500">
                <div>
                    <i class="pi pi-info-circle mr-2"></i>
                    Use the lookup function to find attendees quickly by scanning their badge or entering their ID
                </div>
                <div class="flex items-center space-x-4">
                    <span>System Status: <span class="text-green-600 font-semibold">Online</span></span>
                    <span>Version: 2.1.0</span>
                </div>
            </div>
        </div>
    </div>
</template>
