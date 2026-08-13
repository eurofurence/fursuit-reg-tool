<script setup lang="ts">
/**
 * Shell for the Catch-Em-All game.
 *
 * The document scrolls and the nav is fixed. The previous layout scrolled an
 * inner box (`overflow-y-auto` on a fixed-height div), which cost momentum
 * scrolling, pull to refresh and the collapsing address bar on phones.
 *
 * `hue` is the screen's content colour: a rarity, a player's chosen profile
 * colour, or the colour sampled from a badge photo. It tints the ambient wash,
 * the rule under the header and the section headings. Tokens live on :root in
 * cea.css, not under .cea, because the sheet and lightbox teleport to body.
 */
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppHeader from '@/Components/CatchEmAll/AppHeader.vue'
import BottomNavigation from '@/Components/CatchEmAll/BottomNavigation.vue'
import CatchToasts from '@/Components/CatchEmAll/CatchToasts.vue'
import '../../css/cea.css'

const props = withDefaults(defineProps<{
    title?: string
    subtitle?: string
    /** shown in the header pill, tiers by size */
    count?: number | null
    hue?: string | null
    flash?: any
}>(), {
    count: null,
    hue: null,
})

const hue = computed(() => props.hue || 'var(--cea-accent-bright)')

/*
 * Paint the document itself while the game is open.
 *
 * An overscroll bounce shows what is behind the app, and that was white in three
 * separate places on iOS: the document (html and body had no background at all),
 * the browser chrome tint, which Safari also uses for the rubber-band area, and
 * the PWA splash. The first two are handled here, the third in PWAController.
 * theme-color is shared with the rest of the site, so it is swapped on the way
 * in and restored on the way out rather than changed in the blade layout.
 */
const PAGE = '#0a1017'
let previousTheme: string | null = null

onMounted(() => {
    document.documentElement.classList.add('cea-page')
    document.body.classList.add('cea-page')

    const meta = document.querySelector('meta[name="theme-color"]')
    if (meta) {
        previousTheme = meta.getAttribute('content')
        meta.setAttribute('content', PAGE)
    }
})

onBeforeUnmount(() => {
    document.documentElement.classList.remove('cea-page')
    document.body.classList.remove('cea-page')

    const meta = document.querySelector('meta[name="theme-color"]')
    if (meta && previousTheme !== null) meta.setAttribute('content', previousTheme)
})
</script>

<template>
    <!-- `dark` stays on the root: PrimeVue's theme keys off it, and the selects
         and dialogs these pages still use would otherwise render light -->
    <div class="cea dark" :style="{ '--cea-hue': hue }">
        <Head :title="title || 'Fursuit Catch em all'" />

        <div class="cea-ambient" aria-hidden="true">
            <svg viewBox="0 0 390 800" preserveAspectRatio="none">
                <circle cx="330" cy="-30" r="150" :fill="hue" opacity=".17" />
                <circle cx="20" cy="330" r="120" :fill="hue" opacity=".08" />
                <circle cx="360" cy="720" r="130" :fill="hue" opacity=".1" />
            </svg>
        </div>

        <AppHeader :title="title" :subtitle="subtitle" :count="count" />

        <main class="cea-main">
            <slot />
        </main>

        <CatchToasts :flash="flash" />

        <BottomNavigation />
    </div>
</template>
