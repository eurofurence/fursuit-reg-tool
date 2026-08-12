<script setup lang="ts">
/**
 * A badge photo, or a stand-in when there is none.
 *
 * Badge images are always 3:4 (FursuitImageService locks the crop stencil to it),
 * so every box that shows one reserves that shape and covers it. The fallback is
 * drawn from the name so a fursuit without a photo is still distinguishable from
 * the one next to it, rather than every gap looking identical.
 */
import { computed } from 'vue'

const props = defineProps<{
    src?: string | null
    name?: string | null
    tone?: string | null
    /** square instead of 3:4, for a person's avatar (the identity mirror stores 512x512) */
    square?: boolean
}>()

const seed = computed(() =>
    [...(props.name ?? '?')].reduce((total, char) => total + char.charCodeAt(0), 0))

const width = computed(() => (props.square ? 64 : 48))
const centre = computed(() => width.value / 2)
const shift = computed(() => (props.square ? 0 : -8))
const back = computed(() => props.tone ?? '#22303f')
const fur = computed(() => props.tone ?? '#54687f')
const ears = computed(() => seed.value % 3)
</script>

<template>
    <img v-if="src" :src="src" :alt="name ?? ''" loading="lazy" />
    <svg v-else :viewBox="`0 0 ${width} 64`" preserveAspectRatio="xMidYMid slice" role="img"
         :aria-label="name ?? 'fursuit'">
        <rect :width="width" height="64" :fill="back" />
        <circle :cx="8 + (seed % 8)" :cy="10 + (seed % 6)" r="14" :fill="fur" opacity=".22" />
        <template v-if="ears === 0">
            <path :d="`M${centre - 15} ${30 + shift} ${centre - 17} ${12 + shift}l14 9z`" :fill="fur" />
            <path :d="`M${centre + 15} ${30 + shift} ${centre + 17} ${12 + shift} ${centre + 3} ${21 + shift}z`" :fill="fur" />
        </template>
        <template v-else-if="ears === 1">
            <circle :cx="centre - 14" :cy="23 + shift" r="7.5" :fill="fur" />
            <circle :cx="centre + 14" :cy="23 + shift" r="7.5" :fill="fur" />
        </template>
        <template v-else>
            <path :d="`M${centre - 13} ${32 + shift} ${centre - 16} ${8 + shift}l10 12z`" :fill="fur" />
            <path :d="`M${centre + 13} ${32 + shift} ${centre + 16} ${8 + shift} ${centre + 6} ${20 + shift}z`" :fill="fur" />
        </template>
        <ellipse :cx="centre" :cy="40 + shift" rx="16" ry="15" :fill="fur" />
        <ellipse :cx="centre" :cy="47 + shift" rx="8.5" ry="7" fill="#f6f9fc" opacity=".93" />
        <circle :cx="centre - 6.5" :cy="37 + shift" r="2.5" fill="#0b0f14" />
        <circle :cx="centre + 6.5" :cy="37 + shift" r="2.5" fill="#0b0f14" />
        <path :d="`M${centre} ${44.5 + shift}c-1.6 0-3 1-3 2.1 0 1.2 1.4 2 3 2s3-.8 3-2c0-1.1-1.4-2.1-3-2.1z`" fill="#0b0f14" />
    </svg>
</template>
