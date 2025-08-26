<script setup>
import { computed } from 'vue';

const props = defineProps({
    fursuit: Object,
    rarity: Object,
});

const rarityColorName = computed(() => {

    if (!props.fursuit || !props.rarity) {
        return null;
    }

    const levelToColor = {
        'common': 'gray',
        'uncommon': 'green',
        'rare': 'blue',
        'epic': 'purple',
        'legendary': 'orange'
    };
    console.log('Fursuit Rarity:', props.rarity.level);
    console.log('Rarity Level:', levelToColor[props.rarity.level]);
    return levelToColor[props.rarity.level] || null;
});

const infoBarBgClass = computed(() => {
    if (!rarityColorName.value) {
        return 'bg-white/95';
    }
    if(rarityColorName.value === 'gray') {
        return 'bg-gray-200/95';
    }

    return `bg-${rarityColorName.value}-400`;
});

const infoBarTitleColorClass = computed(() => {
    if (!rarityColorName.value) {
        return 'text-gray-900';
    }
    return `text-${rarityColorName.value}-900`;
});

const infoBarSpeciesColorClass = computed(() => {
    if (!rarityColorName.value) {
        return 'text-gray-600';
    }
    return `text-${rarityColorName.value}-700`;
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
        <div class="absolute bottom-0 left-0 right-0 backdrop-blur-sm p-2 transform translate-y-0 group-hover:translate-y-full transition-transform duration-300" :class="[infoBarBgClass]">
            <h4 class="font-semibold text-sm truncate" :class="[infoBarTitleColorClass]">{{ fursuit.name }}</h4>
            <p class="text-xs truncate" :class="[infoBarSpeciesColorClass]">{{ fursuit.species }}</p>
        </div>
    </div>
</template>

<style scoped>
/* Additional custom styles if needed */
</style>