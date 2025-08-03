<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import CatchEmAllLayout from "@/Layouts/CatchEmAllLayout.vue"
import Button from "primevue/button"
import Message from "primevue/message"
import FlashMessages from "@/Components/FlashMessages.vue"
import InputText from 'primevue/inputtext'
import Card from 'primevue/card'
import ProgressBar from 'primevue/progressbar'
import Badge from 'primevue/badge'
import Divider from 'primevue/divider'
import TabView from 'primevue/tabview'
import TabPanel from 'primevue/tabpanel'
import Avatar from 'primevue/avatar'
import { ref, computed } from 'vue'

defineOptions({ layout: CatchEmAllLayout })

const form = useForm({ catch_code: '' })

const props = defineProps<{
    myUserInfo: {
        id: number
        rank: number
        score: number
        score_till_next: number
        others_behind: number
        percentage: number
        remaining: number
        total_available: number
    },
    userRanking: Array<any>,
    fursuitRanking: Array<any>,
    flash: object,
    caughtFursuit?: {
        name: string
        species: string
        user: string
        image?: string
    } | null
}>()

const page = usePage()
const showCaughtMessage = ref(!!props.caughtFursuit)

const submit = () => {
    form.catch_code = form.catch_code.toUpperCase()
    form.post(route('fcea.dashboard.catch'), {
        onSuccess: () => {
            form.reset()
        }
    })
}

const progressColor = computed(() => {
    if (props.myUserInfo.percentage >= 75) return 'bg-green-500'
    if (props.myUserInfo.percentage >= 50) return 'bg-yellow-500'
    if (props.myUserInfo.percentage >= 25) return 'bg-orange-500'
    return 'bg-red-500'
})

const getRankColor = (rank: number) => {
    if (rank === 1) return 'text-yellow-600'
    if (rank === 2) return 'text-gray-500'
    if (rank === 3) return 'text-orange-600'
    return 'text-gray-700'
}

const getRankIcon = (rank: number) => {
    if (rank === 1) return '👑'
    if (rank === 2) return '🥈'
    if (rank === 3) return '🥉'
    return `#${rank}`
}
</script>

<template>
    <Head title="Catch'em All! - Dashboard" />
    
    <div class="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100">
        <!-- Success Message for Caught Fursuit -->
        <div v-if="showCaughtMessage && caughtFursuit" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black bg-opacity-50">
            <Card class="w-full max-w-sm mx-auto animate-bounce-in">
                <template #header>
                    <div class="text-center py-6 bg-gradient-to-r from-green-400 to-green-600">
                        <div class="text-6xl mb-2">🎉</div>
                        <h2 class="text-2xl font-bold text-white">Amazing!</h2>
                    </div>
                </template>
                <template #content>
                    <div class="text-center space-y-4">
                        <div class="text-xl font-bold text-green-600">
                            You caught a wild {{ caughtFursuit.species }}!
                        </div>
                        <div class="text-lg text-gray-700">
                            <strong>{{ caughtFursuit.name }}</strong>
                        </div>
                        <div class="text-sm text-gray-500">
                            owned by {{ caughtFursuit.user }}
                        </div>
                        <Button @click="showCaughtMessage = false" class="w-full" severity="success">
                            Continue Catching!
                        </Button>
                    </div>
                </template>
            </Card>
        </div>

        <div class="container mx-auto px-4 py-6 max-w-md">
            <!-- Progress Header -->
            <Card class="mb-6 shadow-lg">
                <template #header>
                    <div class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white p-6 rounded-t-lg">
                        <div class="text-center">
                            <div class="text-4xl mb-2">🦊</div>
                            <h1 class="text-2xl font-bold mb-1">Catch'em All!</h1>
                            <div class="text-indigo-200">Your Progress</div>
                        </div>
                    </div>
                </template>
                <template #content>
                    <div class="space-y-6">
                        <!-- Stats Grid -->
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div class="bg-gradient-to-b from-blue-50 to-blue-100 rounded-lg p-4">
                                <div class="text-2xl font-bold text-blue-600">{{ myUserInfo.rank }}</div>
                                <div class="text-xs text-blue-500 uppercase tracking-wide">Your Rank</div>
                            </div>
                            <div class="bg-gradient-to-b from-green-50 to-green-100 rounded-lg p-4">
                                <div class="text-2xl font-bold text-green-600">{{ myUserInfo.score }}</div>
                                <div class="text-xs text-green-500 uppercase tracking-wide">Caught</div>
                            </div>
                            <div class="bg-gradient-to-b from-purple-50 to-purple-100 rounded-lg p-4">
                                <div class="text-2xl font-bold text-purple-600">{{ myUserInfo.remaining }}</div>
                                <div class="text-xs text-purple-500 uppercase tracking-wide">Remaining</div>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm text-gray-600">
                                <span>Progress</span>
                                <span>{{ myUserInfo.percentage }}%</span>
                            </div>
                            <ProgressBar :value="myUserInfo.percentage" class="h-3" />
                            <div class="text-xs text-gray-500 text-center">
                                {{ myUserInfo.score }} of {{ myUserInfo.total_available }} fursuiters caught
                            </div>
                        </div>

                        <!-- Next Rank Info -->
                        <div v-if="myUserInfo.score_till_next > 0" class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                            <div class="text-center text-sm text-yellow-700">
                                <strong>{{ myUserInfo.score_till_next }}</strong> more catches to advance rank!
                            </div>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Flash Messages -->
            <div class="mb-6">
                <FlashMessages :flash="flash" />
            </div>

            <!-- Catch Code Input -->
            <Card class="mb-6 shadow-lg">
                <template #header>
                    <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white p-4 rounded-t-lg">
                        <div class="text-center">
                            <div class="text-3xl mb-2">🎯</div>
                            <h2 class="text-lg font-bold">Enter Catch Code</h2>
                            <div class="text-orange-100 text-sm">Found a fursuiter? Enter their code!</div>
                        </div>
                    </div>
                </template>
                <template #content>
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-gray-700">5-Letter Code</label>
                            <InputText 
                                v-model="form.catch_code" 
                                placeholder="ABCDE"
                                class="w-full text-center text-2xl font-mono tracking-widest uppercase"
                                :class="{ 'border-red-500': form.hasErrors }"
                                maxlength="5"
                                @input="form.catch_code = $event.target.value.toUpperCase()"
                                fluid
                            />
                            <div v-if="form.hasErrors" class="text-red-500 text-sm text-center">
                                Invalid catch code - please try again!
                            </div>
                        </div>
                        <Button 
                            type="submit" 
                            :loading="form.processing"
                            class="w-full py-3 text-lg font-bold"
                            severity="success"
                        >
                            <template #icon>
                                <span class="mr-2">⚡</span>
                            </template>
                            Catch!
                        </Button>
                    </form>
                </template>
            </Card>

            <!-- Leaderboards -->
            <Card class="shadow-lg">
                <template #header>
                    <div class="bg-gradient-to-r from-gray-700 to-gray-900 text-white p-4 rounded-t-lg">
                        <div class="text-center">
                            <div class="text-3xl mb-2">🏆</div>
                            <h2 class="text-lg font-bold">Leaderboards</h2>
                        </div>
                    </div>
                </template>
                <template #content>
                    <TabView>
                        <TabPanel header="🕵️ Catchers">
                            <div v-if="userRanking.length" class="space-y-2">
                                <div 
                                    v-for="user in userRanking" 
                                    :key="user.id"
                                    class="flex items-center justify-between p-3 rounded-lg"
                                    :class="user.id === myUserInfo.id ? 'bg-blue-50 border-2 border-blue-200' : 'bg-gray-50'"
                                >
                                    <div class="flex items-center space-x-3">
                                        <div class="text-xl" :class="getRankColor(user.rank)">
                                            {{ getRankIcon(user.rank) }}
                                        </div>
                                        <div>
                                            <div class="font-medium text-gray-900">
                                                {{ user.user.name }}
                                                <Badge v-if="user.id === myUserInfo.id" value="YOU" severity="info" class="ml-2" />
                                            </div>
                                            <div class="text-sm text-gray-500">Rank {{ user.rank }}</div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-lg font-bold text-gray-900">{{ user.score }}</div>
                                        <div class="text-xs text-gray-500">catches</div>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="text-center py-8 text-gray-500">
                                <div class="text-4xl mb-2">🦊</div>
                                <div class="text-lg">Be the first to catch a fursuiter!</div>
                            </div>
                        </TabPanel>
                        
                        <TabPanel header="🦊 Most Caught">
                            <div v-if="fursuitRanking.length" class="space-y-2">
                                <div 
                                    v-for="fursuit in fursuitRanking" 
                                    :key="fursuit.id"
                                    class="flex items-center justify-between p-3 rounded-lg bg-gray-50"
                                >
                                    <div class="flex items-center space-x-3">
                                        <div class="text-xl" :class="getRankColor(fursuit.rank)">
                                            {{ getRankIcon(fursuit.rank) }}
                                        </div>
                                        <div>
                                            <div class="font-medium text-gray-900">
                                                {{ fursuit.fursuit.name }}
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                {{ fursuit.fursuit.species }} • {{ fursuit.fursuit.user }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-lg font-bold text-gray-900">{{ fursuit.score }}</div>
                                        <div class="text-xs text-gray-500">times caught</div>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="text-center py-8 text-gray-500">
                                <div class="text-4xl mb-2">🎭</div>
                                <div class="text-lg">No fursuiters caught yet!</div>
                            </div>
                        </TabPanel>
                    </TabView>
                </template>
            </Card>

            <!-- How It Works -->
            <Card class="mt-6 shadow-lg">
                <template #header>
                    <div class="bg-gradient-to-r from-teal-500 to-cyan-500 text-white p-4 rounded-t-lg">
                        <div class="text-center">
                            <div class="text-3xl mb-2">❓</div>
                            <h2 class="text-lg font-bold">How It Works</h2>
                        </div>
                    </div>
                </template>
                <template #content>
                    <div class="space-y-4 text-sm text-gray-700">
                        <div class="flex items-start space-x-3">
                            <div class="text-2xl">🔍</div>
                            <div>
                                <div class="font-semibold">Find Fursuiters</div>
                                <div>Look for fursuiters with a 5-digit code on the bottom right of their badge.</div>
                            </div>
                        </div>
                        
                        <div class="flex items-start space-x-3">
                            <div class="text-2xl">📝</div>
                            <div>
                                <div class="font-semibold">Enter the Code</div>
                                <div>Type the code above to "catch" that fursuiter and earn points!</div>
                            </div>
                        </div>
                        
                        <div class="flex items-start space-x-3">
                            <div class="text-2xl">🏆</div>
                            <div>
                                <div class="font-semibold">Climb the Ranks</div>
                                <div>Catch more fursuiters to climb the leaderboard and become the ultimate catcher!</div>
                            </div>
                        </div>
                        
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mt-4">
                            <div class="text-blue-800 text-center font-medium">
                                💡 Each fursuiter can only be caught once per player
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>
    </div>
</template>

<style scoped>
@keyframes bounce-in {
    0% {
        transform: scale(0.3);
        opacity: 0;
    }
    50% {
        transform: scale(1.05);
    }
    70% {
        transform: scale(0.9);
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}

.animate-bounce-in {
    animation: bounce-in 0.6s ease-out;
}

/* Custom progress bar styling */
:deep(.p-progressbar) {
    height: 12px !important;
    border-radius: 6px !important;
    background: #e5e7eb !important;
}

:deep(.p-progressbar .p-progressbar-value) {
    background: linear-gradient(90deg, #10b981, #34d399) !important;
    border-radius: 6px !important;
}

/* Tab styling */
:deep(.p-tabview .p-tabview-nav li .p-tabview-nav-link) {
    font-weight: 600 !important;
}

/* Input styling */
:deep(.p-inputtext:focus) {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
}
</style>