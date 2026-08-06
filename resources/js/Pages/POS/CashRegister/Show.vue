<script setup>
import { Head, Link } from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";


const props = defineProps({
    wallet: Object
});

defineOptions({
    layout: POSLayout,
});

</script>

<template>
    <Head title="Machine Wallet"/>
    <div class="w-full grow flex flex-col items-center justify-center gap-4">
        <div class="pos-card w-full max-w-xl text-center">
            <span class="pos-label">{{ wallet.holder.name }} · drawer balance</span>
            <div class="pos-num text-6xl font-bold mt-2">
                {{ (parseInt(wallet.balance) / 100.0).toFixed(wallet.decimal_places) }} €
            </div>
        </div>

        <div class="grid grid-cols-2 gap-2 w-full max-w-xl">
            <Link :href="route('pos.wallet.money.add')" class="pos-btn pos-btn--commit">
                <i class="pi pi-plus-circle"></i> Add money
            </Link>
            <Link :href="route('pos.wallet.money.remove')" class="pos-btn pos-btn--commit">
                <i class="pi pi-minus-circle"></i> Remove money
            </Link>
        </div>
    </div>
</template>
