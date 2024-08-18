<script lang="ts" setup>
import { Link } from "@inertiajs/vue3";

const props = defineProps({
    label: {
        type: String,
        required: true
    },
    route: {
        type: String,
        default: ''
    }
});
</script>

<template>
    <Link :href="route">
        <div class="px-10 py-8 rounded-lg bg-primary text-white font-semibold text-xl active:bg-primary-active-color cursor-pointer select-none">
            {{ label }}
        </div>
    </Link>
</template>