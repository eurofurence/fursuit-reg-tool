<script lang="ts">
import { defineComponent, ref } from 'vue';
import dayjs from "dayjs";

export default defineComponent({
    setup() {
        const time = ref(dayjs().format('D.MM.YYYY H:mm'));
        setInterval(() => {
            time.value = dayjs().format('D.MM.YYYY H:mm');
        }, 1000 * 60);

        return {
            time,
        };
    },
});
</script>

<template>
    <div>{{ time }}</div>
</template>