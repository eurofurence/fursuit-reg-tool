<script setup>
const props = defineProps({
    fursuit: Object,
});
</script>

<template>
    <div class="bg-white shadow-lg rounded-lg overflow-hidden container transition-all">
        <img
            class="w-full h-full object-cover object-center select-none"
            :src="fursuit.image"
            alt="avatar"
        />
        <div class="p-4 oversight transition-all">
            <p class="text-2xl font-semibold text-gray-800 transition-all">
                {{ fursuit.name }}
            </p>
            <p class="text-sm font-semibold text-gray-500 transition-all">
                {{ fursuit.species }}
            </p>
        </div>
    </div>
</template>

<style scoped>
.container * {
    transition-duration: 300ms;
}

.container {
    position: relative;
}

.oversight {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background-color: #fffe;
    padding: 1rem;
    text-align: center;
    box-shadow: #fffe 0 0 20px 20px;
}

.container:hover .oversight {
    background-color: #fff0;
    box-shadow: #fff0 0 0 20px 20px;
}

.container:hover .oversight p {
    color: #0000;
    user-select: none;
}
</style>

<script>
export default {
    name: "GalleryItem",
};
</script>
