<script setup lang="ts">
/**
 * Fixed bottom nav. The active tab is an accent indicator plus a brighter icon,
 * not a filled slab, and there is no oversized centre button: Catch is a tab
 * like the others.
 */
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Trophy, Medal, Target, LayoutGrid, User } from 'lucide-vue-next'

const props = withDefaults(defineProps<{
    isEventActive: boolean
}>(), {
    isEventActive: false,
})


const items = [
    { key: 'leaderboard', label: 'Ranking', icon: Trophy, route: 'catch-em-all.leaderboard', locked: !props.isEventActive },
    { key: 'achievements', label: 'Achievements', icon: Medal, route: 'catch-em-all.achievements', locked: !props.isEventActive },
    { key: 'catch', label: 'Catch', icon: Target, route: 'catch-em-all.catch', locked: false },
    { key: 'collection', label: 'Collection', icon: LayoutGrid, route: 'catch-em-all.collection', locked: !props.isEventActive },
    { key: 'profile', label: 'You', icon: User, route: 'catch-em-all.profile', locked: false },
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
        <template v-for="item in items" :key="item.key">
            <div
                v-if="item.locked"
                class="opacity-50"
            >
                <span class="cea-nav-icon"><component :is="item.icon" :size="20" /></span>
                {{ item.label }}
            </div>

            <Link
                v-else
                :href="route(item.route)"
                :class="{ 'is-on': current === item.key }"
                preserve-scroll
            >
                <span class="cea-nav-icon"><component :is="item.icon" :size="20" /></span>
                {{ item.label }}
            </Link>
        </template>
    </nav>
</template>
