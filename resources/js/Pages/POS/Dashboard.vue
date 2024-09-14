<script setup>
import { Head, Link } from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";
import DashboardButton from "@/Components/POS/DashboardButton.vue";

defineOptions({
    layout: POSLayout,
});

const buttons = [
    { label: "Lookup Attendee", route: route('pos.attendee.lookup'), icon: 'pi pi-id-card' },
    { label: "Badge List", route: '#', icon: 'pi pi-database' },
    { label: "Cash Register", route: route('pos.wallet.show'), icon: 'pi pi-euro' },
    { label: "Switch User", route: route('pos.auth.user.logout'), icon: 'pi pi-sign-out', method: 'POST' },
];
</script>

<template>
    <Head>
        <title>POS - Dashboard</title>
    </Head>
    <!-- Grid System for POS -->
    <div class="grid lg:grid-cols-4 lg:grid-rows-4 gap-2 h-full px-4 pb-4">
      <DashboardButton :label="i.label" :icon="i.icon" :route="i.route" :method="i.method" v-for="i in buttons">test</DashboardButton>
    </div>
</template>
