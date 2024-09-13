<script setup>
import Button from "primevue/button";
import Menu from "primevue/menu";
import { Link } from "@inertiajs/vue3";
import DigitalClock from "@/Components/POS/DigitalClock.vue";
import Badge from "primevue/badge";
import { ref, computed } from "vue";
import QZPrintService from "@/Components/POS/QZPrintService.vue";
import ToastService from "@/Components/POS/ToastService.vue";
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const cashier = computed(() => page.props.auth.user);

const userMenu = ref();
const userMenuItems = ref([
    { label: 'Switch User', icon: 'pi pi-user', route: route('pos.auth.user.logout'), method: 'POST' },
]);

const toggleUserMenu = (event) => {
    userMenu.value.toggle(event);
};

const props = defineProps({
    attendee: Object || undefined, // from backend
    // layoutBack: String || undefined
});
</script>

<template>
    <ToastService/>
    <!-- Keep this, responsible for printing! -->
    <QZPrintService/>
    <div class="min-h-screen lg:h-screen w-full flex flex-col bg-gray-200">
        <div class="p-4 flex flex-row items-center">
            <Button v-if="layoutBack" icon="pi pi-arrow-left" class="p-button-rounded p-button-text" label="Back" onclick="history.back();return false;" />
            <Badge class="select-none" v-if="attendee" :value="attendee.name + ' #' + attendee.attendee_id" size="large" severity="success"></Badge>
            <div class="flex-grow text-center text-slate-500 font-semibold text-lg">
                <DigitalClock />
            </div>
            <Button type="button" icon="pi pi-user" @click="toggleUserMenu" aria-haspopup="true" aria-controls="overlay_menu" :label="cashier.name" />
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
        <div class="flex-1">
            <slot></slot>
        </div>
    </div>
</template>
