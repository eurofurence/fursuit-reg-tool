<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppHeader from '@/Components/CatchEmAll/AppHeader.vue'
import BottomNavigation from '@/Components/CatchEmAll/BottomNavigation.vue'
import FlashMessages from '@/Components/FlashMessages.vue'

defineProps<{
    title?: string
    subtitle?: string
    flash?: any
}>()
</script>

<template>
    <div class="min-h-screen bg-gradient-to-br from-gray-900 to-gray-800 pb-20 dark">
        <Head :title="title || 'Fursuit Catch em all" />

        <AppHeader :title="title" :subtitle="subtitle" />

        <!-- Content Area with bottom padding for navigation -->
        <div class="px-4 pt-4 pb-4 space-y-4 overflow-y-auto" style="height: calc(100vh - 140px);">
            <!-- Flash Messages -->
            <FlashMessages :flash="flash" />

            <!-- Page Content -->
            <slot />
        </div>

        <BottomNavigation />
    </div>
</template>
