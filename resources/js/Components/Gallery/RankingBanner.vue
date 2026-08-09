<script setup lang="ts">
import { computed } from 'vue';

interface Ranking {
    user: string,
    rank: number,
    catches: number,
}

const props = defineProps<{
    ranking?: Ranking[],
    eventName?: string | null,
}>();

/**
 * Podium order (silver, gold, bronze) so the winner stands in the middle. Places nobody
 * reached yet are dropped rather than rendered as an empty column.
 */
const podium = computed(() => {
    const entries = props.ranking ?? [];

    return [
        { place: 2, entry: entries[1], height: 'h-20 sm:h-24' },
        { place: 1, entry: entries[0], height: 'h-28 sm:h-36' },
        { place: 3, entry: entries[2], height: 'h-16 sm:h-20' },
    ].filter(step => step.entry);
});

function numberFormat(value: number): string {
    return new Intl.NumberFormat().format(value ?? 0);
}
</script>

<template>
    <section v-if="podium.length">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">
            {{ eventName ? `${eventName} leaders` : 'Catch \'Em All leaders' }}
        </h2>

        <ol class="flex items-end justify-center gap-2 sm:gap-4">
            <li v-for="step in podium" :key="step.place" class="w-full max-w-[13rem]">
                <div class="mb-1 text-center">
                    <p class="truncate text-sm font-semibold text-gray-900 sm:text-base">
                        {{ step.entry.user }}
                    </p>
                    <p class="text-xs text-gray-500">
                        {{ numberFormat(step.entry.catches) }} catches
                    </p>
                </div>
                <div
                    class="flex items-end justify-center rounded-t-md border border-b-0 border-gray-200 bg-gray-100"
                    :class="step.height"
                >
                    <span class="pb-2 text-2xl font-bold text-gray-400 sm:text-3xl">{{ step.place }}</span>
                </div>
            </li>
        </ol>
        <div class="border-t border-gray-300"></div>
    </section>
</template>
