<script setup>
import { computed } from 'vue';

// Import SVG files for Vite resolution  
import Euro50 from '/resources/assets/images/pos/money/50EuroSchein.svg';
import Euro20 from '/resources/assets/images/pos/money/20EuroSchein.svg';
import Euro10 from '/resources/assets/images/pos/money/10EuroSchein.svg';
import Euro5 from '/resources/assets/images/pos/money/5euro.svg';
import Euro2 from '/resources/assets/images/pos/money/2euro.svg';
import Euro1 from '/resources/assets/images/pos/money/1euro.svg';
import Cent50 from '/resources/assets/images/pos/money/50cent.svg';
import Cent20 from '/resources/assets/images/pos/money/20cent.svg';
import Cent10 from '/resources/assets/images/pos/money/10cent.svg';
import Cent5 from '/resources/assets/images/pos/money/5_euro_cent.svg';
import Cent2 from '/resources/assets/images/pos/money/2_euro_cent.svg';
import Cent1 from '/resources/assets/images/pos/money/1_euro_cent.svg';

const props = defineProps({
    denomination: Number,
    size: {
        type: String,
        default: 'normal' // normal, small, large
    }
});

const svgMap = {
    50: Euro50,
    20: Euro20,
    10: Euro10,
    5: Euro5,
    2: Euro2,
    1: Euro1,
    0.5: Cent50,
    0.2: Cent20,
    0.1: Cent10,
    0.05: Cent5,
    0.02: Cent2,
    0.01: Cent1
};

const svgPath = computed(() => {
    return svgMap[props.denomination] || null;
});

const sizeClasses = computed(() => {
    const baseSize = props.denomination >= 5 ? 'banknote' : 'coin';
    
    switch (props.size) {
        case 'small':
            return baseSize === 'banknote' ? 'w-16 h-10' : 'w-8 h-8';
        case 'large':
            return baseSize === 'banknote' ? 'w-32 h-20' : 'w-16 h-16';
        default:
            return baseSize === 'banknote' ? 'w-24 h-15' : 'w-12 h-12';
    }
});

const displayValue = computed(() => {
    return props.denomination < 1 ? `${props.denomination * 100}¢` : `${props.denomination}€`;
});
</script>

<template>
    <div class="flex flex-col items-center justify-center p-1">
        <div v-if="svgPath" :class="['flex items-center justify-center', sizeClasses]">
            <img 
                :src="svgPath" 
                :alt="displayValue"
                class="w-full h-full object-contain drop-shadow-sm"
            />
        </div>
        <div v-else class="fallback-cash" :denomination="denomination" :class="sizeClasses">
            <span class="text-xs font-semibold">{{ displayValue }}</span>
        </div>
    </div>
</template>

<style scoped>
.fallback-cash {
    @apply flex items-center justify-center rounded border-2 border-gray-400 bg-gray-200 text-gray-800;
}

.fallback-cash[denomination="200"] {
    @apply bg-yellow-300 border-yellow-400;
}

.fallback-cash[denomination="100"] {
    @apply bg-green-300 border-green-400;
}

.fallback-cash[denomination="50"] {
    @apply bg-orange-300 border-orange-400;
}

.fallback-cash[denomination="20"] {
    @apply bg-blue-300 border-blue-400;
}

.fallback-cash[denomination="10"] {
    @apply bg-red-300 border-red-400;
}

.fallback-cash[denomination="5"] {
    @apply bg-gray-300 border-gray-400;
}

/* Coins */
.fallback-cash[denomination="2"], 
.fallback-cash[denomination="1"],
.fallback-cash[denomination="0.5"],
.fallback-cash[denomination="0.2"],
.fallback-cash[denomination="0.1"],
.fallback-cash[denomination="0.05"],
.fallback-cash[denomination="0.02"],
.fallback-cash[denomination="0.01"] {
    @apply rounded-full bg-yellow-400 border-yellow-500;
}
</style>