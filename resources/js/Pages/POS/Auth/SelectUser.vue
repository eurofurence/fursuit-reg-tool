<script setup>

import Message from "primevue/message";
import SimpleKeyboard from "@/Components/SimpleKeyboard.vue";
import InputOtp from "primevue/inputotp";
import Card from 'primevue/card';
import Button from 'primevue/button';
import {useForm} from "laravel-precognition-vue-inertia";
import {router} from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";

const props = defineProps({
    users: {
        type: Array
    }
});

defineOptions({
    layout: POSLayout,
});

function selectUser(userId) {
    router.visit(route('pos.auth.user.login.show',{user: userId,}));
}

</script>

<template>
    <div class="h-screen flex items-center justify-center text-center p-4">
        <div class="space-y-2">
            <h1 class="text-4xl font-semibold">Select a User</h1>
            <div class="text-lg text-gray-700 pb-3">Please select yourself or scan your authentication badge.</div>
            <div class="grid md:grid-cols-3 gap-3 max-w-lg">
                <Button @click="selectUser(user.id)" v-for="user in users" class="h-24">{{ user.name }}</Button>
            </div>
        </div>
    </div>
</template>

<style scoped>

</style>
