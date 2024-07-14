<script setup>
    import {usePage} from "@inertiajs/vue3";
    import {formatEuroFromCents} from "../helpers.js";

    const balance = usePage().props.auth.balance;
</script>

<template>
    <div class="bg-gray-50 rounded-lg mb-4 shadow border-2 border-yellow-500" v-if="balance < 0">
        <div class="p-4 text-center">
            <div class="text-4xl font-semibold font-main mb-4 lg:mb-2">{{ formatEuroFromCents(balance * -1) }}</div>
            <p class="text-sm">
                The outstanding amount needs to be paid <strong>at the Con</strong>.
            </p>
        </div>
    </div>
</template>

<style scoped>

</style>
