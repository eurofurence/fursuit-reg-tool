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
    },
    icon: {
        type: String,
        default: ''
    }
});
</script>

<template>
    <Link :href="route">
        <div class="
        h-full
        px-10
        py-8
        rounded-lg
        bg-white
        shadow
        border-gray-400
        text-black
        font-semibold
        text-xl
        cursor-pointer
        select-none
        relative
        text-right
        hover:bg-gray-100
        duration-200
        overflow-hidden
">
            <span class="absolute  opacity-10 left-5 text-[4rem] -bottom-4 lg:-bottom-10 lg:text-[9rem]" :class="icon"></span>
            {{ label }}
        </div>
    </Link>
</template>
