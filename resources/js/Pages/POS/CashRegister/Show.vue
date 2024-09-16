<script setup>
import { Head } from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";
import DashboardButton from "@/Components/POS/DashboardButton.vue";


const props = defineProps({
    wallet: Object
});

defineOptions({
    layout: POSLayout,
});

</script>

<template>
    <Head title="Machine Wallet"/>
    <div class="grow flex flex-col h-full items-center">
        <h1 class="text-xl text-gray-600">{{ wallet.holder.name }}</h1>
        <div class="text-gray-800 text-6xl mt-4 mb-40">
            {{ (parseInt(wallet.balance) / 100.0).toFixed(wallet.decimal_places) }} €
        </div>

        <div class="flex flex-row gap-4">
            <DashboardButton label="Add Money" icon="pi pi-plus-circle" :route="route('pos.wallet.money.add')" class="w-48" />
            <DashboardButton label="Remove Money" icon="pi pi-minus-circle" :route="route('pos.wallet.money.remove')" class="w-48" />
        </div>
    </div>
</template>
