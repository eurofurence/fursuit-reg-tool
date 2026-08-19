<script setup>
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import {onMounted, watch} from "vue";
import {usePage} from "@inertiajs/vue3";

const toast = useToast();

// watch page props for flash messages and add them to the toast
onMounted(() => {
    checkToast();
});

watch(() => usePage().props.flash, () => {
    checkToast();
});

/*
 * Both used to sit at 1000ms. A clerk who presses Print and then looks down at
 * the printer has already missed it by the time they look back, which is how
 * "did that do anything?" turns into a second card. A failure is worse: "Badge
 * could not be queued for printing" flashing for one second reads as success,
 * so an error stays up long enough to be read twice.
 */
const SUCCESS_LIFE = 2500;
const ERROR_LIFE = 8000;

function checkToast() {
    const page = usePage();
    if (page.props.flash.success) {
        toast.add({severity: 'success', summary: 'Success', detail: page.props.flash.success, life: SUCCESS_LIFE});
    }
    if (page.props.flash.error) {
        toast.add({severity: 'error', summary: 'Error', detail: page.props.flash.error, life: ERROR_LIFE});
    }
}
</script>

<template>
    <Toast position="top-center" />
</template>

<style scoped>

</style>
