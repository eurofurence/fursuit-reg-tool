<script setup lang="ts">
/**
 * Fixed bottom nav. The active tab is an accent indicator plus a brighter icon,
 * not a filled slab, and there is no oversized centre button: Catch is a tab
 * like the others.
 */
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Trophy, Medal, Target, LayoutGrid, User } from 'lucide-vue-next'

const items = [
    { key: 'leaderboard', label: 'Ranking', icon: Trophy, route: 'catch-em-all.leaderboard' },
    { key: 'achievements', label: 'Achievements', icon: Medal, route: 'catch-em-all.achievements' },
    { key: 'catch', label: 'Catch', icon: Target, route: 'catch-em-all.catch' },
    { key: 'collection', label: 'Collection', icon: LayoutGrid, route: 'catch-em-all.collection' },
    { key: 'profile', label: 'You', icon: User, route: 'catch-em-all.profile' },
]

const current = computed(() => {
    const name = route().current() ?? ''
    if (name.includes('leaderboard')) return 'leaderboard'
    if (name.includes('achievements')) return 'achievements'
    if (name.includes('collection')) return 'collection'
    if (name.includes('profile')) return 'profile'
    return 'catch'
})
</script>

<template>
    <nav class="cea-nav">
        <Link
            v-for="item in items"
            :key="item.key"
            :href="route(item.route)"
            :class="{ 'is-on': current === item.key }"
            preserve-scroll
        >
            <span class="cea-nav-icon"><component :is="item.icon" :size="20" /></span>
            {{ item.label }}
        </Link>
    </nav>
</template>
