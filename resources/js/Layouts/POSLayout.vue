<script setup lang="ts">
import Button from "primevue/button";
import Menu from "primevue/menu";
import DigitalClock from "@/Components/POS/DigitalClock.vue";
import { ref } from "vue";

const userMenu = ref();
const userMenuItems = ref([
    { label: 'Logout', icon: 'pi pi-sign-out' },
    { label: 'Switch User', icon: 'pi pi-user' }
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
            <Menu ref="userMenu" id="overlay_menu" :model="userMenuItems" :popup="true" />
        </div>
        <slot></slot>
    </div>
</template>