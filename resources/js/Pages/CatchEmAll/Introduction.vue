<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import Button from "primevue/button"
import Card from 'primevue/card'
import {
    Target,
    Star,
    ArrowRight,
    Play,
    Search,
    Trophy,
} from 'lucide-vue-next'

const form = useForm({
    _token: null // Ensure CSRF token is included
})

const submit = () => {
    console.log('Submit button clicked, posting to:', route('catch-em-all.introduction.complete'))
    form.post(route('catch-em-all.introduction.complete'), {
        onSuccess: (page) => {
            console.log('Success:', page)
        },
        onError: (errors) => {
            console.log('Errors:', errors)
        },
        onFinish: () => {
            console.log('Request finished')
        }
    })
}

const steps = [
    {
        icon: Search,
        title: "Find Fursuiters",
        description: "Look for fursuiters wearing badges with a 5-letter code like 'ABC12'.",
        color: "text-blue-600",
        bg: "bg-blue-50"
    },
    {
        icon: Target,
        title: "Enter the Code",
        description: "Type the code into the app to 'catch' that fursuiter.",
        color: "text-green-600",
        bg: "bg-green-50"
    },
    {
        icon: Star,
        title: "Collect Them All",
        description: "Complete your collection by catching all fursuiters at the event.",
        color: "text-purple-600",
        bg: "bg-purple-50"
    },
    {
        icon: Trophy,
        title: "Compete & Win",
        description: "Climb the leaderboard and unlock achievements!",
        color: "text-yellow-600",
        bg: "bg-yellow-50"
    }
]

</script>

<template>
    <div class="dark min-h-screen bg-gradient-to-br from-gray-900 to-gray-800 text-white">
        <!-- Hero Section -->
        <div class="relative px-4 pt-12 pb-8">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold mb-3 bg-gradient-to-r from-blue-400 to-purple-400 bg-clip-text text-transparent">
                    Welcome to the Fursuit Catch 'Em All
                </h1>
                <p class="text-gray-300 text-lg leading-relaxed">
                    Collect all the fursuiters roaming around the convention.
                </p>
            </div>

            <!-- Animated Background Elements -->
            <div class="absolute top-10 left-10 w-20 h-20 bg-blue-500/10 rounded-full animate-pulse"></div>
            <div class="absolute top-32 right-8 w-16 h-16 bg-purple-500/10 rounded-full animate-pulse delay-300"></div>
            <div class="absolute bottom-20 left-6 w-12 h-12 bg-green-500/10 rounded-full animate-pulse delay-700"></div>
        </div>

        <!-- How It Works -->
        <div class="px-4 pb-8">
            <Card class="bg-gray-800 border border-gray-700 shadow-xl">
                <template #title>
                    <h2 class="text-xl font-bold mb-6 text-white">How it works:</h2>
                </template>
                <template #content>

                    <div class="space-y-6">
                        <div v-for="(step, index) in steps" :key="index" class="flex items-start space-x-4">
                            <!-- Step Content -->
                            <div class="flex-1">
                                <div class="flex items-center space-x-3 mb-2">
                                    <div class="w-10 h-10 rounded-lg flex items-center justify-center" :class="step.bg">
                                        <component :is="step.icon" class="w-5 h-5" :class="step.color" />
                                    </div>
                                    <h3 class="font-semibold text-white">{{ step.title }}</h3>
                                </div>
                                <p class="text-gray-300 text-sm">{{ step.description }}</p>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>



        <!-- Rules & Tips -->
        <div class="px-4 pb-8">
            <Card class="bg-gray-800 border border-gray-700 shadow-xl">
                <template #content>
                    <h2 class="text-xl font-bold mb-6 text-white">Always remember:</h2>

                    <div class="space-y-4">
                        <div class="flex items-start space-x-3">
                            <div class="w-6 h-6 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <div class="w-2 h-2 bg-white rounded-full"></div>
                            </div>
                            <div>
                                <div class="font-medium text-white">Be Respectful</div>
                                <div class="text-sm text-gray-300">Always ask before taking photos or approaching fursuiters.</div>
                            </div>
                        </div>

                        <div class="flex items-start space-x-3">
                            <div class="w-6 h-6 rounded-full bg-purple-500 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <div class="w-2 h-2 bg-white rounded-full"></div>
                            </div>
                            <div>
                                <div class="font-medium text-white">Have Fun!</div>
                                <div class="text-sm text-gray-300">Enjoy meeting new people and discovering amazing fursuits!</div>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Start Button -->
        <div class="px-4 pb-12">
            <Button
                @click="submit"
                :loading="form.processing"
                class="w-full py-4 text-lg font-bold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 border-0 rounded-xl shadow-lg"
            >
                <Play class="w-6 h-6 mr-3" />
                {{ form.processing ? 'Getting Ready...' : 'Start collecting!' }}
                <ArrowRight class="w-6 h-6 ml-3" />
            </Button>
        </div>
    </div>
</template>

<style scoped>
/* Enhanced card styling for dark mode */
:deep(.p-card) {
    border-radius: 16px !important;
    background: transparent !important;
}

/* Enhanced button styling */
:deep(.p-button) {
    border-radius: 12px !important;
    font-weight: 700 !important;
    transition: all 0.3s ease !important;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2) !important;
}

:deep(.p-button:hover) {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3) !important;
}

/* Animation for floating elements */
@keyframes float {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-10px); }
}

.animate-float {
    animation: float 3s ease-in-out infinite;
}

/* Pulse animation for background elements */
@keyframes pulse-slow {
    0%, 100% { opacity: 0.1; transform: scale(1); }
    50% { opacity: 0.2; transform: scale(1.1); }
}

.animate-pulse {
    animation: pulse-slow 4s ease-in-out infinite;
}
</style>
