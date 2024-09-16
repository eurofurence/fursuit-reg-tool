<script setup>
const props = defineProps({
    denomination: Number,
});
</script>

<template>
    <div class="flex cash" :denomination="denomination">
        <span v-if="denomination < 1">{{ denomination * 100 }}¢</span>
        <span v-else>{{ denomination }}</span>
    </div>
</template>

<style scoped>
.cash {
    font-size: 1.5rem;
    text-align: center;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
}

.cash[denomination="200"] {
    background: #edcf7c;
    min-width: 6rem;
    min-height: 3rem;
}

.cash[denomination="100"] {
    background: #a0e674;
    min-width: 6rem;
    min-height: 3rem;
}

.cash[denomination="50"] {
    font-size: 1.25rem;
    background: #ff9d70;
    min-width: 5rem;
    min-height: 2.5rem;
}

.cash[denomination="20"] {
    font-size: 1.25rem;
    background: #7accff;
    min-width: 4.5rem;
    min-height: 2.25rem;
}

.cash[denomination="10"] {
    font-size: 1.2rem;
    background: #ff766c;
    min-width: 4rem;
    min-height: 2.1rem;
}

.cash[denomination="5"] {
    font-size: 1.1rem;
    background: #acc5c6;
    min-width: 3.5rem;
    min-height: 1.75rem;
}

.cash[denomination="2"] {
    background: #d7be50;
    border: 0.5rem solid #bed2d4;
    border-radius: 100%;
    min-width: 3.5rem;
    min-height: 3.5rem;
    width: 3.5rem;
    height: 3.5rem;
}

.cash[denomination="1"] {
    background: #d0dedf;
    border: 0.5rem solid #d7be50;
    border-radius: 100%;
    min-width: 3rem;
    min-height: 3rem;
    width: 3rem;
    height: 3rem;
}

.cash[denomination="0.5"] {
    background: #c5a237;
    border-radius: 100%;
    font-size: 1.2rem;
    min-width: 3rem;
    min-height: 3rem;
    width: 3rem;
    height: 3rem;
}

.cash[denomination="0.2"] {
    background: #c5a237;
    border-radius: 100%;
    font-size: 1rem;
    min-width: 2.25rem;
    min-height: 2.25rem;
    width: 2.25rem;
    height: 2.25rem;
}

.cash[denomination="0.1"] {
    background: #c5a237;
    border-radius: 100%;
    font-size: 0.9rem;
    min-width: 2.1rem;
    min-height: 2.1rem;
    width: 2.1rem;
    height: 2.1rem;
}

.cash[denomination="0.05"] {
    background: #e35d39;
    border-radius: 100%;
    font-size: 0.8rem;
    min-width: 1.75rem;
    min-height: 1.75rem;
    width: 1.75rem;
    height: 1.75rem;
}

.cash[denomination="0.02"] {
    background: #e35d39;
    border-radius: 100%;
    font-size: 0.7rem;
    min-width: 1.5rem;
    min-height: 1.5rem;
    width: 1.5rem;
    height: 1.5rem;
}

.cash[denomination="0.01"] {
    background: #e35d39;
    border-radius: 100%;
    font-size: 0.7rem;
    min-width: 1.5rem;
    min-height: 1.5rem;
    width: 1.5rem;
    height: 1.5rem;
}
</style>
