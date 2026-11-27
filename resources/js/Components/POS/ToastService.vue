<script setup>
import { onMounted, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Toaster } from '@/Components/UI/sonner';

const page = usePage();

onMounted(checkToast);

watch(() => page.props.flash, checkToast);

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
    const flash = page.props.flash ?? {};
    if (flash.success) {
        toast.success(flash.success, { duration: SUCCESS_LIFE });
    }
    if (flash.error) {
        toast.error(flash.error, { duration: ERROR_LIFE });
    }
}
</script>

<template>
    <Toaster position="top-center" rich-colors class="pos-surface" />
</template>
