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

function checkToast() {
    const page = usePage();
    if (page.props.flash.success) {
        toast.add({severity: 'success', summary: 'Success', detail: page.props.flash.success, life: 1000});
    }
    if (page.props.flash.error) {
        toast.add({severity: 'error', summary: 'Error', detail: page.props.flash.error, life: 1000});
    }
}
</script>

<template>
    <Toast position="top-center" />
</template>

<style scoped>

</style>
