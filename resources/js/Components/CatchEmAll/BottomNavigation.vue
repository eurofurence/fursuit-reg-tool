<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { 
    Trophy, 
    Award, 
    BookOpen, 
    Target, 
    User,
    Medal,
    Gem,
    Library
} from 'lucide-vue-next'

const page = usePage()

// Get current route name to determine active tab
const currentRoute = computed(() => {
    const routeName = page.props.ziggy?.route?.current || ''
    if (routeName.includes('leaderboard')) return 'leaderboard'
    if (routeName.includes('achievements')) return 'achievements'
    if (routeName.includes('collection')) return 'collection'
    if (routeName.includes('catch')) return 'catch'
    return 'catch' // default
})

const navItems = [
    {
        key: 'leaderboard',
        label: 'Leaderboard',
        icon: Medal,
        route: 'catch-em-all.leaderboard',
        color: 'text-yellow-400 bg-yellow-900/30'
    },
    {
        key: 'achievements',
        label: 'Achievements',
        icon: Gem,
        route: 'catch-em-all.achievements',
        color: 'text-purple-400 bg-purple-900/30'
    },
    {
        key: 'catch',
        label: 'Catch!',
        icon: Target,
        route: 'catch-em-all.catch',
        color: 'text-white bg-gradient-to-br from-blue-600 to-purple-600 scale-110 shadow-lg',
        isCenter: true
    },
    {
        key: 'collection',
        label: 'Collection',
        icon: Library,
        route: 'catch-em-all.collection',
        color: 'text-green-400 bg-green-900/30'
    },
    {
        key: 'profile',
        label: 'Profile',
        icon: User,
        route: null, // No route yet
        color: 'text-gray-500',
        disabled: true
    }
]
</script>

<template>
    <!-- Bottom Navigation - Mobile App Style -->
    <div class="fixed bottom-0 left-0 right-0 bg-gray-800 border-t border-gray-700 shadow-lg safe-area-bottom">
        <div class="grid grid-cols-5 items-center py-2">
            <template v-for="item in navItems" :key="item.key">
                <!-- Regular Navigation Item -->
                <component 
                    :is="item.disabled ? 'button' : 'Link'" 
                    :href="item.route ? route(item.route) : null"
                    :disabled="item.disabled"
                    @click="item.disabled ? null : undefined"
                    class="flex flex-col items-center justify-center rounded-lg transition-all transform mx-auto"
                    :class="[
                        item.isCenter ? 'p-3 rounded-xl -mt-2' : 'p-2',
                        currentRoute === item.key ? item.color : (item.disabled ? 'text-gray-500' : 'text-gray-400'),
                        item.disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:scale-105',
                        item.isCenter && currentRoute !== item.key ? 'bg-gray-700' : ''
                    ]"
                >
                    <component 
                        :is="item.icon" 
                        :class="[
                            item.isCenter ? 'w-8 h-8 mb-1' : 'w-6 h-6 mb-1'
                        ]" 
                    />
                    <span :class="[
                        'font-medium',
                        item.isCenter ? 'text-sm font-bold' : 'text-xs'
                    ]">
                        {{ item.label }}
                    </span>
                </component>
            </template>
        </div>
    </div>
</template>

<style scoped>
/* Mobile app navigation transitions */
button, a {
    transition: all 0.2s ease;
}

button:active, a:active {
    transform: scale(0.95);
}

/* Safe area handling for devices with notches */
.safe-area-bottom {
    padding-bottom: env(safe-area-inset-bottom, 0);
}
</style>