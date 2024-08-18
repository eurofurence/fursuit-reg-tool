<script setup lang="ts">
import Button from "primevue/button";
import Menu from "primevue/menu";
import { Link } from "@inertiajs/vue3";
import DigitalClock from "@/Components/POS/DigitalClock.vue";
import { ref } from "vue";

const userMenu = ref();
const userMenuItems = ref([
    { label: 'Logout', icon: 'pi pi-sign-out', route: route('pos.auth.user.logout'), method: 'POST' },
    { label: 'Switch User', icon: 'pi pi-user', route: '', method: 'POST' },
]);

const toggleUserMenu = (event) => {
    userMenu.value.toggle(event);
};
</script>

<template>
    <div class="min-h-screen w-full flex flex-col bg-gray-200">
        <div class="p-4 flex flex-row items-center">
            <!-- <Button icon="pi pi-bars" class="p-button-rounded p-button-text" /> -->
            <div class="flex-grow text-center text-slate-500 font-semibold text-lg">
                <DigitalClock />
            </div>
            <Button type="button" icon="pi pi-user" @click="toggleUserMenu" aria-haspopup="true" aria-controls="overlay_menu" label="User X" />
            <Menu ref="userMenu" id="overlay_menu" :model="userMenuItems" :popup="true">
                <template #item="{ item, props }">
                    <Link :href="item.route" :method="item.method" v-ripple>
                        <div class="flex items-center px-4 py-2">
                            <span :class="item.icon"></span>
                            <span class="ml-2">{{ item.label }}</span>
                        </div>
                    </Link>
                </template>
            </Menu>
        </div>
        <slot></slot>
    </div>
</template>
