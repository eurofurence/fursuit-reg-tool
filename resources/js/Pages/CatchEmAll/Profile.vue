<script setup lang="ts">
import CatchEmAllLayout from "@/Layouts/CatchEmAllLayout.vue";

const props = defineProps<{
    flash?: any;
}>();
</script>

<template>
    <CatchEmAllLayout
        title="Profile"
        subtitle=""
        :flash="flash"
        icon="profile"
    >
        <div class="text-white text-center justify-center">
            <h1>Saving space for something special next year at Eurofurence 30</h1>
        </div>

    </CatchEmAllLayout>
</template>

<style scoped>

</style>
