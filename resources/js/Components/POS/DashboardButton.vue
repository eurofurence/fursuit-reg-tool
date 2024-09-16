<script lang="ts" setup>
import { Link } from "@inertiajs/vue3";

const props = defineProps({
    label: {
        type: String,
        required: true
    },
    subtitle: {
        type: String,
        default: ''
    },
    route: {
        type: String,
        default: ''
    },
    method: {
        type: String,
        default: 'GET'
    },
    icon: {
        type: String,
        default: ''
    }
});
</script>

<template>
    <component :is="(route) ? Link : 'div'" :href="route" :method="method">
        <div class="
        h-full
        px-10
        py-8
        rounded-lg
        bg-white
        shadow
        border-gray-400
        text-black
        cursor-pointer
        select-none
        relative
        text-right
        hover:bg-gray-100
        duration-200
        overflow-hidden
">
            <span class="absolute  opacity-10 left-5 text-[4rem] -bottom-4 lg:-bottom-7 lg:text-[7rem]" :class="icon"></span>
            <div>
                <div class="font-semibold ">{{ label }}</div>
                <div v-if="subtitle">{{ subtitle }}</div>
            </div>
        </div>
    </component>
</template>
