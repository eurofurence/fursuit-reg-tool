<script setup>
import { computed } from 'vue';

const props = defineProps({
    fursuit: Object,
    rarity: Object,
    hideCount: Boolean
});

const rarityConfig = computed(() => {
    const level = props.rarity?.level || 'common';
    
    const config = {
        'common': {
            bgClass: 'bg-gray-100/95',
            titleClass: 'text-gray-900',
            speciesClass: 'text-gray-700',
            shadowClass: '',
            cardGlow: '',
            cardRing: '',
            badgeClass: 'bg-slate-600 text-white border-white/20'
        },
        'uncommon': {
            bgClass: 'bg-emerald-200/95',
            titleClass: 'text-emerald-900 font-semibold', 
            speciesClass: 'text-emerald-800',
            shadowClass: 'shadow-emerald-200/50',
            cardGlow: 'shadow-emerald-400/20 hover:shadow-emerald-400/40',
            cardRing: '',
            badgeClass: 'bg-emerald-600 text-white border-emerald-400/30 shadow-emerald-200/50'
        },
        'rare': {
            bgClass: 'bg-blue-200/95',
            titleClass: 'text-blue-900 font-bold',
            speciesClass: 'text-blue-800',
            shadowClass: 'shadow-blue-200/50',
            cardGlow: 'shadow-blue-400/30 hover:shadow-blue-400/50',
            cardRing: '',
            badgeClass: 'bg-blue-600 text-white border-blue-400/30 shadow-blue-200/50'
        },
        'epic': {
            bgClass: 'bg-violet-300/95',
            titleClass: 'text-violet-900 font-bold',
            speciesClass: 'text-violet-800 font-medium',
            shadowClass: 'shadow-lg shadow-violet-300/60',
            cardGlow: 'shadow-violet-400/40 hover:shadow-violet-400/60 epic-glow',
            cardRing: 'ring-1 ring-violet-400/30',
            badgeClass: 'bg-gradient-to-br from-violet-600 to-violet-700 text-white border-violet-400/40 shadow-violet-300/60'
        },
        'legendary': {
            bgClass: 'bg-gradient-to-r from-amber-200/95 to-orange-200/95',
            titleClass: 'text-amber-900 font-bold',
            speciesClass: 'text-amber-800 font-medium',
            shadowClass: 'shadow-xl shadow-amber-300/70',
            cardGlow: 'shadow-amber-400/50 hover:shadow-amber-400/70 legendary-glow',
            cardRing: 'ring-1 ring-amber-400/30',
            badgeClass: 'bg-gradient-to-br from-amber-500 to-orange-600 text-white border-amber-300/50 shadow-amber-300/70'
        }
    };

    const result = config[level] || config.common;
    return { ...result, level };
});

</script>

<template>
    <div class="group relative bg-white rounded-xl shadow-md hover:shadow-xl transition-all duration-300 overflow-hidden"
         :class="[rarityConfig.cardGlow, rarityConfig.cardRing]">
        <div class="overflow-hidden">
            <img
                :src="fursuit.image"
                :alt="fursuit.name"
                class="w-full h-full object-cover object-center transition-transform duration-300 group-hover:scale-110"
                loading="lazy"
            />
        </div>

        <!-- Overlay with fursuit info -->
        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
            <div class="absolute bottom-0 left-0 right-0 p-4 text-white">
                <h3 class="text-lg font-bold mb-1 transform translate-y-2 group-hover:translate-y-0 transition-transform duration-300 drop-shadow-lg">
                    {{ fursuit.name }}
                </h3>
                <p class="text-sm opacity-90 transform translate-y-2 group-hover:translate-y-0 transition-transform duration-300 delay-75 drop-shadow-md">
                    {{ fursuit.species }}
                </p>
            </div>
        </div>

        <!-- Always visible info bar at bottom -->
        <div class="absolute bottom-0 left-0 right-0 backdrop-blur-sm p-2 transform translate-y-0 group-hover:translate-y-full transition-transform duration-300" 
             :class="[rarityConfig.bgClass, rarityConfig.shadowClass]">
            <h4 class="text-sm truncate" :class="[rarityConfig.titleClass]">{{ fursuit.name }}</h4>
            <p class="text-xs truncate" :class="[rarityConfig.speciesClass]">{{ fursuit.species }}</p>
        </div>

                <!-- Scoring badge -->
        <div v-if="fursuit.scoring > 0 && !hideCount" 
             class="absolute top-3 right-3 text-xs font-bold px-2 py-1 rounded-full shadow-lg border"
             :class="[rarityConfig.badgeClass]">
            {{ fursuit.scoring }}
        </div>

    </div>
</template>

<style scoped>
/* Enhanced glow animations */
.epic-glow {
    animation: epic-pulse 3s ease-in-out infinite;
}

@keyframes epic-pulse {
    0%, 100% {
        box-shadow: 
            0 4px 6px -1px rgba(0, 0, 0, 0.1), 
            0 2px 4px -1px rgba(0, 0, 0, 0.06),
            0 0 0 rgba(139, 92, 246, 0);
    }
    50% {
        box-shadow: 
            0 4px 6px -1px rgba(0, 0, 0, 0.1), 
            0 2px 4px -1px rgba(0, 0, 0, 0.06),
            0 0 20px rgba(139, 92, 246, 0.3);
    }
}

.legendary-glow {
    animation: legendary-pulse 2.5s ease-in-out infinite;
}

@keyframes legendary-pulse {
    0%, 100% {
        box-shadow: 
            0 10px 15px -3px rgba(0, 0, 0, 0.1), 
            0 4px 6px -2px rgba(0, 0, 0, 0.05),
            0 0 0 rgba(251, 191, 36, 0);
    }
    50% {
        box-shadow: 
            0 10px 15px -3px rgba(0, 0, 0, 0.1), 
            0 4px 6px -2px rgba(0, 0, 0, 0.05),
            0 0 25px rgba(251, 191, 36, 0.4),
            0 0 40px rgba(251, 191, 36, 0.2);
    }
}
</style>