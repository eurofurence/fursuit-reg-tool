<script setup lang="ts">

interface Ranking {
    user: string,
    rank: number,
    catches: number,
}

const props = defineProps({
    ranking: Array<Ranking>,
});
</script>

<template>
    <div class="ranking-banner flex flex-col justify-between items-center bg-primary-500/60 shadow rounded py-2">
        <h2 class="text-center font-bold mb-4">This year's Catch-am-all winners</h2>
        <div class="ranking-items flex justify-between w-full">
            <div class="ranking-item text-silver font-bold" @mouseover="showScore(1)" @mouseleave="showName(1)">
                <span v-if="!showScores[1]">#2 {{ props.ranking[1]?.user }}</span>
                <span v-else>{{ props.ranking[1]?.catches }}</span>
            </div>
            <div class="ranking-item text-gold font-bold" @mouseover="showScore(0)" @mouseleave="showName(0)">
                <span v-if="!showScores[0]">#1 {{ props.ranking[0]?.user }}</span>
                <span v-else>{{ props.ranking[0]?.catches }}</span>
            </div>
            <div class="ranking-item text-bronze font-bold" @mouseover="showScore(2)" @mouseleave="showName(2)">
                <span v-if="!showScores[2]">#3 {{ props.ranking[2]?.user }}</span>
                <span v-else>{{ props.ranking[2]?.catches }}</span>
            </div>
        </div>
    </div>
</template>

<script lang="ts">
import { ref } from 'vue';

const showScores = ref([false, false, false]);

function showScore(index) {
    showScores.value[index] = true;
}

function showName(index) {
    showScores.value[index] = false;
}
</script>

<style scoped>
.ranking-banner {
    width: 100%;
    padding: 10px;
}

.ranking-items {
    display: flex;
    justify-content: space-between;
    width: 100%;
}

.ranking-item {
    flex: 1;
    text-align: center;
    font-size: 1.5rem;
}

.text-gold {
    color: gold;
}

.text-silver {
    color: silver;
}

.text-bronze {
    color: rgb(205, 127, 50);
}
</style>
