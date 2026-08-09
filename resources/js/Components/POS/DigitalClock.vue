<script lang="ts">
import { defineComponent, onUnmounted, ref } from 'vue';
import dayjs from "dayjs";

export default defineComponent({
    setup() {
        // Time only: the desk knows what day it is, and the seconds-free clock
        // is what staff read off when timing a queue.
        const format = () => dayjs().format('HH:mm');
        const time = ref(format());

        const tick = setInterval(() => {
            time.value = format();
        }, 1000 * 10);

        onUnmounted(() => clearInterval(tick));

        return {
            time,
        };
    },
});
</script>

<template>
    <div>{{ time }}</div>
</template>
