<script setup lang="ts">
/**
 * Sticky header: title, subtitle, and the caught count.
 *
 * The count tiers as it grows (bronze at 10, silver at 25, gold at 50, purple at
 * 100) so the header says something at a glance rather than repeating a number
 * the page already shows, and it counts up when it changes.
 */
import { ref, watch } from "vue";

const props = defineProps<{
    title?: string;
    subtitle?: string;
    count?: number | null;
}>();

const TIERS = [
    { at: 151, cls: "t5", word: "legend" },
    { at: 100, cls: "t4", word: "archivist" },
    { at: 70, cls: "t3", word: "catcher" },
    { at: 69, cls: "t0", word: "69" },
    { at: 50, cls: "t3", word: "catcher" },
    { at: 20, cls: "t2", word: "curator" },
    { at: 10, cls: "t1", word: "collector" },
    { at: 0, cls: "", word: "" },
];

const shown = ref(props.count ?? 0);
const tier = ref(TIERS.find((t) => (props.count ?? 0) >= t.at)!);
const bumping = ref(false);

watch(
    () => props.count,
    (to, from) => {
        if (to == null) return;
        const next = TIERS.find((t) => to >= t.at)!;
        if (next.cls !== tier.value.cls) {
            bumping.value = true;
            setTimeout(() => (bumping.value = false), 400);
        }
        tier.value = next;

        const start = performance.now();
        const was = from ?? to;
        const step = (now: number) => {
            const k = Math.min(1, (now - start) / 260);
            shown.value = Math.round(was + (to - was) * k);
            if (k < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    },
);
</script>

<template>
    <header class="cea-head">
        <!-- the bar spans the window, its contents sit in the same column as the page -->
        <div class="cea-head-inner">
            <div style="min-width: 0">
                <h1>{{ title || "Catch 'Em All" }}</h1>
                <p v-if="subtitle">{{ subtitle }}</p>
            </div>
            <span
                v-if="count !== null"
                class="cea-pill"
                :class="[tier.cls, { bump: bumping }]"
            >
                {{ shown }} caught<span v-if="tier.word" class="tier">{{
                    tier.word
                }}</span>
            </span>
        </div>
    </header>
</template>
