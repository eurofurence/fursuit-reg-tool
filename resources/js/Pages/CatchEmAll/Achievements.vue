<script setup lang="ts">
import CatchEmAllLayout from '@/Layouts/CatchEmAllLayout.vue'
import Card from 'primevue/card'
import {
    Award,
    Star,
    Shield,
    Zap,
    Target,
    Trophy,
    Crown,
    Users,
    Clock,
    CheckCircle,
    Circle
} from 'lucide-vue-next'

const props = defineProps<{
    achievements: Array<any>,
    flash?: any
}>()

// Group achievements by completion status
const completedAchievements = props.achievements.filter(a => a.completed)
const inProgressAchievements = props.achievements.filter(a => !a.completed && a.progress > 0)
const lockedAchievements = props.achievements.filter(a => !a.completed && a.progress === 0)

// Get achievement category icon
const getCategoryIcon = (achievementType: string) => {
    switch (achievementType) {
        case 'first_catch': return Target
        case 'species_diversity': return Star
        case 'rare_collector': return Crown
        case 'point_accumulator': return Trophy
        case 'streak_master': return Zap
        case 'social_hunter': return Users
        case 'speed_hunter': return Clock
        default: return Award
    }
}

// Get achievement rarity color
const getRarityColor = (achievementType: string) => {
    switch (achievementType) {
        case 'first_catch': return 'text-green-600'
        case 'species_diversity': return 'text-blue-600'
        case 'rare_collector': return 'text-purple-600'
        case 'point_accumulator': return 'text-yellow-600'
        case 'streak_master': return 'text-red-600'
        case 'social_hunter': return 'text-pink-600'
        case 'speed_hunter': return 'text-indigo-600'
        default: return 'text-gray-600'
    }
}

// Get achievement background color
const getRarityBg = (achievementType: string) => {
    switch (achievementType) {
        case 'first_catch': return 'bg-green-50 border-green-200'
        case 'species_diversity': return 'bg-blue-50 border-blue-200'
        case 'rare_collector': return 'bg-purple-50 border-purple-200'
        case 'point_accumulator': return 'bg-yellow-50 border-yellow-200'
        case 'streak_master': return 'bg-red-50 border-red-200'
        case 'social_hunter': return 'bg-pink-50 border-pink-200'
        case 'speed_hunter': return 'bg-indigo-50 border-indigo-200'
        default: return 'bg-gray-50 border-gray-200'
    }
}

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    })
}
</script>

<template>
    <CatchEmAllLayout title="Achievements" subtitle="Your progress and unlocks" :flash="flash">
        <!-- Stats Overview -->
        <Card class="bg-gray-800 border border-gray-700 shadow-sm">
            <template #content>
                <div class="text-center mb-4">
                    <div class="w-16 h-16 mx-auto mb-3 bg-gradient-to-br from-purple-500 to-pink-600 rounded-full flex items-center justify-center">
                        <Award class="w-8 h-8 text-white" />
                    </div>
                    <h2 class="text-xl font-bold text-gray-100">Achievement Progress</h2>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div class="text-center pt-4 pb-4 p-0.5 bg-green-900/20 rounded-lg border border-green-700 achievement-icon">
                        <CheckCircle class="w-8 h-8 mx-auto mb-2 text-green-600" />
                        <div class="text-2xl font-bold text-green-400">{{ completedAchievements.length }}</div>
                        <div class="text-sm text-green-300">Completed</div>
                    </div>
                    <div class="text-center pt-4 pb-4 p-0.5 bg-blue-900/20 rounded-lg border border-blue-700 achievement-icon">
                        <Clock class="w-8 h-8 mx-auto mb-2 text-blue-600" />
                        <div class="text-2xl font-bold text-blue-400">{{ inProgressAchievements.length }}</div>
                        <div class="text-sm text-blue-300">In Progress</div>
                    </div>
                    <div class="text-center pt-4 pb-4 p-0.5 bg-gray-700/50 rounded-lg border border-gray-600 achievement-icon">
                        <Circle class="w-8 h-8 mx-auto mb-2 text-gray-400" />
                        <div class="text-2xl font-bold text-gray-300">{{ lockedAchievements.length }}</div>
                        <div class="text-sm text-gray-400">Locked</div>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Completed Achievements -->
        <div v-if="completedAchievements.length > 0">
            <h3 class="text-lg font-bold text-gray-100 mb-3 flex items-center">
                <CheckCircle class="w-6 h-6 mr-2 text-green-400" />
                Completed ({{ completedAchievements.length }})
            </h3>
            <div class="space-y-3 mb-6">
                <Card v-for="achievement in completedAchievements" :key="achievement.id" class="bg-white shadow-sm">
                    <template #content>
                        <div class="flex items-center space-x-4 p-2">
                            <!-- Achievement Icon -->
                            <div class="w-14 h-14 rounded-full flex items-center justify-center border-2 border-green-300 bg-green-100">
                                <component :is="getCategoryIcon(achievement.achievement)" class="w-7 h-7 text-green-600" />
                            </div>

                            <!-- Achievement Info -->
                            <div class="flex-1">
                                <div class="flex items-center space-x-2 mb-1">
                                    <h4 class="font-semibold text-gray-800">{{ achievement.title }}</h4>
                                    <Star class="w-5 h-5 text-yellow-500 fill-current" />
                                </div>
                                <p class="text-sm text-gray-600 mb-2">{{ achievement.description }}</p>
                                <div class="flex items-center justify-between">
                                    <div class="text-xs text-green-600 font-medium">
                                        ✅ Completed on {{ formatDate(achievement.earnedAt) }}
                                    </div>
                                    <div class="text-sm font-bold text-green-600">
                                        {{ achievement.maxProgress > 1 ? `${achievement.progress}/${achievement.maxProgress}` : '100%' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </Card>
            </div>
        </div>

        <!-- In Progress Achievements -->
        <div v-if="inProgressAchievements.length > 0">
            <h3 class="text-lg font-bold text-gray-100 mb-3 flex items-center">
                <Clock class="w-6 h-6 mr-2 text-blue-400" />
                In Progress ({{ inProgressAchievements.length }})
            </h3>
            <div class="space-y-3 mb-6">
                <Card v-for="achievement in inProgressAchievements" :key="achievement.id" class="bg-white shadow-sm">
                    <template #content>
                        <div class="flex items-center space-x-4 p-2">
                            <!-- Achievement Icon -->
                            <div class="w-14 h-14 rounded-full flex items-center justify-center border-2"
                                 :class="'border-blue-300 bg-blue-100'">
                                <component :is="getCategoryIcon(achievement.achievement)" class="w-7 h-7 text-blue-600" />
                            </div>

                            <!-- Achievement Info -->
                            <div class="flex-1">
                                <h4 class="font-semibold text-gray-800 mb-1">{{ achievement.title }}</h4>
                                <p class="text-sm text-gray-600 mb-3">{{ achievement.description }}</p>

                                <!-- Progress Bar -->
                                <div class="space-y-2">
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-600">Progress</span>
                                        <span class="font-medium text-blue-600">
                                            {{ achievement.progress }}/{{ achievement.maxProgress }} ({{ achievement.progressPercentage }}%)
                                        </span>
                                    </div>
                                    <div class="h-3 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="h-full bg-gradient-to-r from-blue-500 to-blue-600 rounded-full transition-all duration-500"
                                             :style="`width: ${achievement.progressPercentage}%`"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </Card>
            </div>
        </div>

        <!-- Locked Achievements -->
        <div v-if="lockedAchievements.length > 0">
            <h3 class="text-lg font-bold text-gray-100 mb-3 flex items-center">
                <Circle class="w-6 h-6 mr-2 text-gray-400" />
                Locked ({{ lockedAchievements.length }})
            </h3>
            <div class="space-y-3">
                <Card v-for="achievement in lockedAchievements" :key="achievement.id" class="bg-white shadow-sm opacity-75">
                    <template #content>
                        <div class="flex items-center space-x-4 p-2">
                            <!-- Achievement Icon -->
                            <div class="w-14 h-14 rounded-full flex items-center justify-center border-2 border-gray-300 bg-gray-100">
                                <component :is="getCategoryIcon(achievement.achievement)" class="w-7 h-7 text-gray-400" />
                            </div>

                            <!-- Achievement Info -->
                            <div class="flex-1">
                                <h4 class="font-semibold text-gray-600 mb-1">{{ achievement.title }}</h4>
                                <p class="text-sm text-gray-500 mb-2">{{ achievement.description }}</p>
                                <div class="text-xs text-gray-400 font-medium">
                                    🔒 Start hunting to unlock!
                                </div>
                            </div>
                        </div>
                    </template>
                </Card>
            </div>
        </div>

        <!-- Empty State -->
        <div v-if="achievements.length === 0" class="text-center py-12">
            <Award class="w-20 h-20 mx-auto mb-4 text-gray-300" />
            <h3 class="text-xl font-medium text-gray-600 mb-2">No achievements available</h3>
            <p class="text-gray-500">Start catching fursuiters to unlock achievements!</p>
        </div>
    </CatchEmAllLayout>
</template>

<style scoped>
/* Enhanced card styling */
:deep(.p-card) {
    border-radius: 12px !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
    border: 1px solid rgba(0, 0, 0, 0.05) !important;
}

/* Achievement icon animations */
.achievement-icon {
    transition: all 0.3s ease;
}

.achievement-icon:hover {
    transform: scale(1.1);
}

/* Progress bar animation */
.progress-bar {
    transition: width 0.8s ease-out;
}
</style>
