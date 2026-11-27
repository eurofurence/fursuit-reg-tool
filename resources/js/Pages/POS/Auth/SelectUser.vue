<script setup>
import {router} from "@inertiajs/vue3";
import { QrCode, User, Users } from "@lucide/vue";
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
                        <Users class="size-16 mx-auto text-pos-accent mb-4" aria-hidden="true" />
                    </div>
                    <h1 class="text-3xl font-bold text-pos-text mb-3">Select a User</h1>
                    <p class="text-lg text-pos-muted leading-relaxed">
                        Please select your user account to continue.
                    </p>
                </div>

                <!-- User Selection Grid -->
                <div class="pos-card">
                        <div class="p-4">
                            <h2 class="text-xl font-semibold text-pos-text mb-4 text-center">Available Users</h2>
                            <div class="grid grid-cols-1 gap-3">
                                <button
                                    type="button"
                                    @click="selectUser(user.id)"
                                    v-for="user in users"
                                    :key="user.id"
                                    class="pos-btn user-select-btn w-full h-16 text-lg"
                                    :aria-label="`Select user ${user.name}`"
                                >
                                    <div class="flex flex-col items-center space-y-2">
                                        <User class="size-5" aria-hidden="true" />
                                        <span class="font-semibold">{{ user.name }}</span>
                                    </div>
                                </button>
                            </div>
                        </div>
                </div>
            </div>

            <!-- Right Column -->
            <div>
                <!-- Badge Scan Section -->
                <div class="pos-card h-full flex items-center justify-center">
                        <div class="p-6 text-center">
                            <div class="mb-6">
                                <QrCode class="size-16 mx-auto text-pos-warn" aria-hidden="true" />
                            </div>
                            <h3 class="text-2xl font-semibold text-pos-text mb-4">Badge Authentication</h3>
                            <p class="text-lg text-pos-muted">You can also scan your authentication badge to log in quickly.</p>
                        </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
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
