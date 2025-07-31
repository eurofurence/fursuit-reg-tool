<script setup>
const props = defineProps({
    fursuit: Object,
});
</script>

<template>
    <div class="group relative bg-white rounded-xl shadow-md hover:shadow-xl transition-all duration-300 overflow-hidden">
        <div class="overflow-hidden">
            <img
                :src="fursuit.image"
                :alt="fursuit.name"
                class="w-full h-full object-cover object-center transition-transform duration-300 group-hover:scale-110"
                loading="lazy"
            />
        </div>
        
        <!-- Overlay with fursuit info -->
        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
            <div class="absolute bottom-0 left-0 right-0 p-4 text-white">
                <h3 class="text-lg font-bold mb-1 transform translate-y-2 group-hover:translate-y-0 transition-transform duration-300">
                    {{ fursuit.name }}
                </h3>
                <p class="text-sm opacity-90 transform translate-y-2 group-hover:translate-y-0 transition-transform duration-300 delay-75">
                    {{ fursuit.species }}
                </p>
            </div>
        </div>
        
        <!-- Always visible info bar at bottom -->
        <div class="absolute bottom-0 left-0 right-0 bg-white/95 backdrop-blur-sm p-3 transform translate-y-0 group-hover:translate-y-full transition-transform duration-300">
            <h4 class="font-semibold text-gray-900 text-sm truncate">{{ fursuit.name }}</h4>
            <p class="text-xs text-gray-600 truncate">{{ fursuit.species }}</p>
        </div>
        
        <!-- Scoring badge -->
        <div class="absolute top-3 right-3 bg-blue-600 text-white text-xs font-bold px-2 py-1 rounded-full shadow-lg">
            {{ fursuit.scoring }}
        </div>
    </div>
</template>

<style scoped>
/* Additional custom styles if needed */
</style>
