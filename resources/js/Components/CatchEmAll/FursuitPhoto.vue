<script setup lang="ts">
/**
 * A badge photo.
 *
 * Badge images are always 3:4 (FursuitImageService locks the crop stencil to it),
 * so every box that shows one reserves that shape and covers it. When a fursuit
 * has no photo yet the box stays empty in its own tone rather than showing a
 * drawn stand-in, which only ever read as a broken image.
 */
defineProps<{
    src?: string | null
    name?: string | null
    tone?: string | null
}>()
</script>

<template>
    <img v-if="src" :src="src" :alt="name ?? ''" loading="lazy" />
    <span
        v-else
        class="cea-nophoto"
        :style="{ '--cea-tone': tone ?? 'var(--cea-line-soft)' }"
        :aria-label="name ? `${name}, no photo` : 'no photo'"
        role="img"
    />
</template>

<style scoped>
.cea-nophoto {
    display: block;
    width: 100%;
    height: 100%;
    background: color-mix(in srgb, var(--cea-tone) 22%, var(--cea-panel-2));
}
</style>
