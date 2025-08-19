<script setup lang="ts">
import CatchEmAllLayout from "@/Layouts/CatchEmAllLayout.vue";
import { useForm } from "@inertiajs/vue3";
import {
    Award,
    BookOpen,
    Crown,
    Star,
    Target,
    TrendingUp,
    Trophy,
    User,
    Zap,
} from "lucide-vue-next";
import Button from "primevue/button";
import Card from "primevue/card";
import Dialog from "primevue/dialog";
import InputText from "primevue/inputtext";
import { computed, nextTick, onMounted, ref } from "vue";

const form = useForm({ catch_code: "" });

const props = defineProps<{
    gameStats: {
        rank: number;
        totalCatches: number;
        uniqueSpecies: number;
        totalAvailable: number;
        completionPercentage: number;
        rarityStats: Record<string, any>;
    };
    achievements: Array<any>;
    recentCatch?: any | null;
    flash?: any;
}>();

const closedID = ref(null);
const showRecentCatch = computed({
    get: () => {
        if (closedID.value === props.recentCatch?.id) {
            return false;
        }
        return !!props.recentCatch;
    },
    set: (value) => {
        if (value == false) {
            closedID.value = props.recentCatch?.id;
        }
    },
});

const submit = () => {
    if (form.processing) return; // Prevent multiple submissions

    form.catch_code = form.catch_code.toUpperCase();
    form.post(route("catch-em-all.catch.submit"), {
        onSuccess: () => {
            form.reset();
        },
        preserveScroll: true, // Preserve scroll position
    });
};

const formatCode = (value: string) => {
    return value
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, "")
        .substring(0, 5);
};

const onCodeInput = (event: any) => {
    const formatted = formatCode(event.target.value);
    form.catch_code = formatted;
    event.target.value = formatted;
};

// Auto-focus input on mount and handle cleanup
const codeInput = ref();
onMounted(() => {
    // Use nextTick to ensure component is mounted
    nextTick(() => {
        if (codeInput.value?.$el) {
            codeInput.value.$el.focus();
        }
    });

    // Reset form when navigating away
    window.addEventListener("beforeunload", () => {
        form.reset();
    });
});

const getRankIcon = (rank: number) => {
    if (rank === 1) return Crown;
    if (rank === 2) return Trophy;
    if (rank === 3) return Award;
    if (rank <= 10) return Star;
    return TrendingUp;
};
</script>

<template>
    <CatchEmAllLayout
        title="Fursuit Catch em All"
        subtitle="Catch them all!"
        :flash="flash"
    >
        <!-- Recent Catch Celebration -->
        <Dialog
            v-model:visible="showRecentCatch"
            :modal="true"
            class="mx-4 dark-dialog"
            :style="{ width: '90vw', maxWidth: '400px' }"
        >
            <template #header>
                <div class="text-center w-full">
                    <div
                        class="w-16 h-16 mx-auto mb-3 bg-green-900/30 rounded-full flex items-center justify-center"
                    >
                        <Star class="w-8 h-8 text-green-400" />
                    </div>
                    <h2 class="text-xl font-bold text-green-400">
                        Amazing Catch!
                    </h2>
                </div>
            </template>

            <div v-if="recentCatch" class="text-center space-y-4">
                <div
                    class="relative mx-auto w-24 h-24 rounded-full overflow-hidden border-4"
                    :class="`border-${recentCatch.rarity.color.replace(
                        'text-',
                        ''
                    )}-500`"
                >
                    <img
                        v-if="recentCatch.image"
                        :src="recentCatch.image"
                        :alt="recentCatch.name"
                        class="w-full h-full object-cover"
                    />
                    <div
                        v-else
                        class="w-full h-full bg-gradient-to-br from-gray-200 to-gray-400 flex items-center justify-center"
                    >
                        <User class="w-8 h-8 text-gray-500" />
                    </div>
                    <div
                        class="absolute -top-2 -right-2 w-6 h-6 bg-white rounded-full flex items-center justify-center shadow-lg"
                    >
                        <Star
                            class="w-4 h-4"
                            :class="recentCatch.rarity.color"
                        />
                    </div>
                </div>

                <div>
                    <div class="text-xl font-bold">{{ recentCatch.name }}</div>
                    <div class="text-sm text-gray-600">
                        {{ recentCatch.species }}
                    </div>
                    <div class="text-xs text-gray-500">
                        owned by {{ recentCatch.user }}
                    </div>
                </div>

                <div
                    class="bg-gradient-to-r p-3 rounded-lg text-white"
                    :class="recentCatch.rarity.gradient"
                >
                    <div class="font-bold">{{ recentCatch.rarity.label }}</div>
                </div>

                <Button
                    @click="showRecentCatch = false"
                    class="w-full"
                    severity="success"
                >
                    <Target class="w-4 h-4 mr-2" />
                    Continue Hunting!
                </Button>
            </div>
        </Dialog>

        <!-- Stats Overview Card -->
        <Card class="bg-gray-800 border border-gray-700 shadow-sm">
            <template #content>
                <div class="space-y-4">
                    <!-- User Stats Row -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div
                                class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center"
                            >
                                <component
                                    :is="getRankIcon(gameStats.rank)"
                                    class="w-6 h-6 text-white"
                                />
                            </div>
                            <div>
                                <div class="text-lg font-bold text-gray-100">
                                    Rank #{{ gameStats.rank }}
                                </div>
                                <div class="text-sm text-gray-300">
                                    {{
                                        gameStats.totalCatches.toLocaleString()
                                    }}
                                    catches
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-300">Progress</span>
                            <span class="font-medium"
                                >{{ gameStats.completionPercentage }}%</span
                            >
                        </div>
                        <div
                            class="h-2 bg-gray-200 rounded-full overflow-hidden"
                        >
                            <div
                                class="h-full bg-gradient-to-r from-blue-500 to-purple-600 rounded-full transition-all duration-500"
                                :style="`width: ${gameStats.completionPercentage}%`"
                            ></div>
                        </div>
                        <div class="text-xs text-gray-400 text-center">
                            {{ gameStats.totalCatches }} /
                            {{ gameStats.totalAvailable }} fursuiters
                        </div>
                    </div>

                    <!-- Quick Stats Grid -->
                    <div class="grid grid-cols-3 gap-3 pt-2">
                        <div class="text-center p-3 bg-blue-900/20 rounded-lg">
                            <BookOpen
                                class="w-5 h-5 mx-auto mb-1 text-blue-400"
                            />
                            <div class="text-lg font-bold text-blue-400">
                                {{ gameStats.uniqueSpecies }}
                            </div>
                            <div class="text-xs text-blue-300">Species</div>
                        </div>
                        <div class="text-center p-3 bg-green-900/20 rounded-lg">
                            <Award
                                class="w-5 h-5 mx-auto mb-1 text-green-400"
                            />
                            <div class="text-lg font-bold text-green-400">
                                {{
                                    achievements.filter((a) => a.completed)
                                        .length
                                }}
                            </div>
                            <div class="text-xs text-green-300">
                                Achievements
                            </div>
                        </div>
                        <div
                            class="text-center p-3 bg-purple-900/20 rounded-lg"
                        >
                            <TrendingUp
                                class="w-5 h-5 mx-auto mb-1 text-purple-400"
                            />
                            <div class="text-lg font-bold text-purple-400">
                                {{ 0 }}
                            </div>
                            <div class="text-xs text-purple-300">
                                Avg Points
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Code Input Card -->
        <Card class="bg-gray-800 border border-gray-700 shadow-sm">
            <template #content>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="text-center mb-4">
                        <div
                            class="w-16 h-16 mx-auto mb-3 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center"
                        >
                            <Target class="w-8 h-8 text-white" />
                        </div>
                        <h2 class="text-lg font-bold text-gray-100">
                            Spot a Fursuiter?
                        </h2>
                        <p class="text-sm text-gray-300">
                            Enter their 5-letter code!
                        </p>
                    </div>

                    <div class="space-y-3">
                        <InputText
                            ref="codeInput"
                            v-model="form.catch_code"
                            placeholder="ABC12"
                            class="w-full text-center text-2xl font-mono tracking-widest uppercase p-4 rounded-lg border-2"
                            :class="{
                                'border-red-500': form.hasErrors,
                                'border-gray-600 focus:border-blue-400 bg-gray-700 text-white':
                                    !form.hasErrors,
                            }"
                            maxlength="5"
                            @input="onCodeInput"
                            fluid
                        />

                        <Button
                            type="submit"
                            :loading="form.processing"
                            class="w-full py-3 text-lg font-bold bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 border-0 rounded-lg"
                            :disabled="form.catch_code.length !== 5"
                        >
                            <Zap class="w-5 h-5 mr-2" />
                            {{ form.processing ? "Catching..." : "Catch!" }}
                        </Button>

                        <div
                            v-if="form.hasErrors"
                            class="text-red-400 text-sm text-center bg-red-900/20 p-3 rounded-lg"
                        >
                            {{
                                Object.values(form.errors)[0] ||
                                "Invalid code - try again!"
                            }}
                        </div>
                    </div>
                </form>
            </template>
        </Card>
    </CatchEmAllLayout>
</template>

<style scoped>
/* Custom animations for mobile app feel */
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

/* Custom input styling for mobile */
:deep(.p-inputtext) {
    border-radius: 8px !important;
    font-size: 18px !important;
    border: 2px solid #d1d5db !important;
}

:deep(.p-inputtext:focus) {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
}

/* Enhanced button styling */
:deep(.p-button) {
    border-radius: 8px !important;
    font-weight: 600 !important;
    transition: all 0.2s ease !important;
}

:deep(.p-button:hover) {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
}

/* Card styling improvements for dark mode */
:deep(.p-card) {
    border-radius: 12px !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3) !important;
    background: transparent !important;
}

/* Dark mode dialog styling */
:deep(.dark-dialog .p-dialog) {
    background: #1f2937 !important;
    border: 1px solid #374151 !important;
}

:deep(.dark-dialog .p-dialog-header) {
    background: #1f2937 !important;
    border-bottom: 1px solid #374151 !important;
    color: #f3f4f6 !important;
}

/* Dark mode input styling */
:deep(.p-inputtext) {
    background: #374151 !important;
    border: 2px solid #4b5563 !important;
    color: #f9fafb !important;
}

:deep(.p-inputtext:focus) {
    border-color: #60a5fa !important;
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.1) !important;
}
</style>
