<script setup>
import Card from 'primevue/card';
import Button from 'primevue/button';
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
    <div class="w-full min-h-full">
        <div class="grid grid-cols-2 gap-6 h-full p-4">
            <!-- Left Column -->
            <div>
                <!-- Header Section -->
                <div class="text-center mb-8">
                    <div class="mb-4">
                        <i class="pi pi-users text-6xl text-blue-500 mb-4"></i>
                    </div>
                    <h1 class="text-3xl font-bold text-slate-800 mb-3">Select a User</h1>
                    <p class="text-lg text-slate-600 leading-relaxed">
                        Please select your user account to continue.
                    </p>
                </div>

                <!-- User Selection Grid -->
                <Card class="shadow-lg border-0">
                    <template #content>
                        <div class="p-4">
                            <h2 class="text-xl font-semibold text-slate-700 mb-4 text-center">Available Users</h2>
                            <div class="grid grid-cols-1 gap-3">
                                <Button 
                                    @click="selectUser(user.id)" 
                                    v-for="user in users" 
                                    :key="user.id"
                                    class="user-select-btn h-16 text-lg font-medium border-2 border-slate-200 hover:border-blue-400 bg-white hover:bg-blue-50 text-slate-700 hover:text-blue-700 transition-all duration-200 shadow-sm hover:shadow-md transform hover:-translate-y-1"
                                    :aria-label="`Select user ${user.name}`"
                                >
                                    <div class="flex flex-col items-center space-y-2">
                                        <i class="pi pi-user text-xl"></i>
                                        <span class="font-semibold">{{ user.name }}</span>
                                    </div>
                                </Button>
                            </div>
                        </div>
                    </template>
                </Card>
            </div>

            <!-- Right Column -->
            <div>
                <!-- Badge Scan Section -->
                <Card class="shadow-lg border-0 h-full flex items-center justify-center">
                    <template #content>
                        <div class="p-6 text-center">
                            <div class="mb-6">
                                <i class="pi pi-qrcode text-6xl text-amber-500"></i>
                            </div>
                            <h3 class="text-2xl font-semibold text-slate-700 mb-4">Badge Authentication</h3>
                            <p class="text-lg text-slate-600">You can also scan your authentication badge to log in quickly.</p>
                        </div>
                    </template>
                </Card>
            </div>
        </div>
    </div>
</template>

<style scoped>
.user-select-btn {
    border-radius: 12px;
    position: relative;
    overflow: hidden;
}

.user-select-btn:hover {
    transform: translateY(-2px);
}

.user-select-btn:active {
    transform: translateY(0);
}

/* Enhanced touch targets for tablets */
@media (max-width: 1024px) {
    .user-select-btn {
        min-height: 6rem;
        font-size: 1.25rem;
    }
}

/* Mobile optimization */
@media (max-width: 640px) {
    .user-select-btn {
        min-height: 5rem;
        font-size: 1.1rem;
    }
}
</style>
