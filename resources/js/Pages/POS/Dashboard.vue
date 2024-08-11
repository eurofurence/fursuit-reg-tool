<script lang="ts" setup>
import { Head, Link } from "@inertiajs/vue3";
import Layout from "@/Layouts/POSLayout.vue";
import DashboardButton from "@/Components/POS/DashboardButton.vue";

defineOptions({
    layout: Layout
});
</script>

<template>
    <Head>
        <title>POS - Dashboard</title>
    </Head>
    <div class="grow flex flex-col">
        <div class="grow flex flex-wrap gap-10 justify-center items-center">
            <DashboardButton label="Lookup Attendee" />
            <DashboardButton label="Badge List" />
            <DashboardButton label="Cash Register" />
        </div>
    </div>
</template>