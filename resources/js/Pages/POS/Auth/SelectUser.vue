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
    <div class="max-w-4xl mx-auto">
        <!-- Header Section -->
        <div class="text-center mb-8">
            <div class="mb-4">
                <i class="pi pi-users text-6xl text-blue-500 mb-4"></i>
            </div>
            <h1 class="text-4xl font-bold text-slate-800 mb-3">Select a User</h1>
            <p class="text-xl text-slate-600 max-w-2xl mx-auto leading-relaxed">
                Please select your user account or scan your authentication badge to continue.
            </p>
        </div>

        <!-- User Selection Grid -->
        <Card class="shadow-lg border-0">
            <template #content>
                <div class="p-6">
                    <h2 class="text-2xl font-semibold text-slate-700 mb-6 text-center">Available Users</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <Button 
                            @click="selectUser(user.id)" 
                            v-for="user in users" 
                            :key="user.id"
                            class="user-select-btn h-20 sm:h-24 text-lg font-medium border-2 border-slate-200 hover:border-blue-400 bg-white hover:bg-blue-50 text-slate-700 hover:text-blue-700 transition-all duration-200 shadow-sm hover:shadow-md transform hover:-translate-y-1"
                            :aria-label="`Select user ${user.name}`"
                        >
                            <div class="flex flex-col items-center space-y-2">
                                <i class="pi pi-user text-2xl"></i>
                                <span class="font-semibold">{{ user.name }}</span>
                            </div>
                        </Button>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Badge Scan Section -->
        <Card class="mt-6 shadow-lg border-0">
            <template #content>
                <div class="p-6 text-center">
                    <div class="mb-4">
                        <i class="pi pi-qrcode text-4xl text-amber-500"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-slate-700 mb-2">Badge Authentication</h3>
                    <p class="text-slate-600">You can also scan your authentication badge to log in quickly.</p>
                </div>
            </template>
        </Card>
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
