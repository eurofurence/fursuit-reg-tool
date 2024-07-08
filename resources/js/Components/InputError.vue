<script setup>
const props = defineProps({
    error: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <div v-if="props.error" class="text-red-600 text-xs">{{error}}</div>
</template>

<style scoped>

</style>
