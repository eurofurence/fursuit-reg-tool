<script setup lang="ts">
import { onMounted, ref, watch } from "vue";
import CatchEmAllLayout from "@/Layouts/CatchEmAllLayout.vue";
import {
    Award,
    CheckCircle,
    Circle,
    Clock,
    Crown,
    Star,
    Target,
    Trophy,
    Users,
    Zap,
} from "lucide-vue-next";
import Card from "primevue/card";

type Achievement = {
    id: number;
    title: string;
    description: string;
    task: string;
    achievement: string;
    completed: boolean;
    progress: number;
    maxProgress: number;
    progressPercentage: number;
    earnedAt?: string | null;
    isOptional: boolean;
    isLocked: boolean;
    hiddenByLock: boolean;
    expandable: boolean;
    progressDetail?: {
        totalProgress: string[];
        currentProgress: string[];
    };
};

type AchievementSnapshot = {
    completed: boolean;
    progress: number;
    maxProgress: number;
    progressPercentage: number;
    isLocked: boolean;
    isOptional: boolean;
};

type AchievementSnapshotMap = Record<string, AchievementSnapshot>;

const ACHIEVEMENT_SNAPSHOT_STORAGE_KEY =
    "cea:achievements:progress-snapshot:v1";
const COMPLETED_SECTION_OPEN_STORAGE_KEY =
    "cea:achievements:completed-section-open:v1";

const extendedAchievements = ref<number[]>([]);
const isCompletedSectionOpen = ref<boolean>(true);
const newlyCompletedAchievementIds = ref<number[]>([]);
const updatedProgressAchievementIds = ref<number[]>([]);

const buildSnapshotMap = (
    achievements: Achievement[],
): AchievementSnapshotMap => {
    return achievements.reduce((snapshot, achievement) => {
        snapshot[String(achievement.id)] = {
            completed: achievement.completed,
            progress: achievement.progress,
            maxProgress: achievement.maxProgress,
            progressPercentage: achievement.progressPercentage,
            isLocked: achievement.isLocked,
            isOptional: achievement.isOptional,
        };

        return snapshot;
    }, {} as AchievementSnapshotMap);
};

const readSnapshotMap = (): AchievementSnapshotMap | null => {
    if (typeof window === "undefined") {
        return null;
    }

    try {
        const raw = window.localStorage.getItem(
            ACHIEVEMENT_SNAPSHOT_STORAGE_KEY,
        );

        if (!raw) {
            return null;
        }

        return JSON.parse(raw) as AchievementSnapshotMap;
    } catch {
        return null;
    }
};

const writeSnapshotMap = (achievements: Achievement[]): void => {
    if (typeof window === "undefined") {
        return;
    }

    try {
        const snapshot = buildSnapshotMap(achievements);
        window.localStorage.setItem(
            ACHIEVEMENT_SNAPSHOT_STORAGE_KEY,
            JSON.stringify(snapshot),
        );
    } catch {
        // Ignore storage write errors (e.g. disabled storage/private mode).
    }
};

const readCompletedSectionOpenState = (): boolean | null => {
    if (typeof window === "undefined") {
        return null;
    }

    try {
        const raw = window.localStorage.getItem(
            COMPLETED_SECTION_OPEN_STORAGE_KEY,
        );

        if (raw === null) {
            return null;
        }

        return raw === "true";
    } catch {
        return null;
    }
};

const writeCompletedSectionOpenState = (isOpen: boolean): void => {
    if (typeof window === "undefined") {
        return;
    }

    try {
        window.localStorage.setItem(
            COMPLETED_SECTION_OPEN_STORAGE_KEY,
            String(isOpen),
        );
    } catch {
        // Ignore storage write errors (e.g. disabled storage/private mode).
    }
};

const updateAchievementChangeMarkers = (achievements: Achievement[]): void => {
    const previousSnapshot = readSnapshotMap();

    newlyCompletedAchievementIds.value = [];
    updatedProgressAchievementIds.value = [];

    if (!previousSnapshot) {
        writeSnapshotMap(achievements);

        return;
    }

    for (const achievement of achievements) {
        const previous = previousSnapshot[String(achievement.id)];

        if (!previous) {
            continue;
        }

        const becameCompleted = !previous.completed && achievement.completed;
        const hasProgressIncrease =
            !achievement.completed &&
            !achievement.isLocked &&
            achievement.progress > previous.progress;

        if (becameCompleted) {
            newlyCompletedAchievementIds.value.push(achievement.id);
            continue;
        }

        if (hasProgressIncrease) {
            updatedProgressAchievementIds.value.push(achievement.id);
        }
    }

    writeSnapshotMap(achievements);
};

const isNewlyCompleted = (achievementId: number): boolean => {
    return newlyCompletedAchievementIds.value.includes(achievementId);
};

const hasProgressUpdate = (achievementId: number): boolean => {
    return updatedProgressAchievementIds.value.includes(achievementId);
};

const toggleAchievementExpansion = (achievementId: number) => {
    const index = extendedAchievements.value.indexOf(achievementId);
    if (index > -1) {
        // Achievement is already expanded, collapse it
        extendedAchievements.value.splice(index, 1);
    } else {
        // Achievement is not expanded, expand it
        extendedAchievements.value.push(achievementId);
    }
    console.log("Extended Achievements:", extendedAchievements.value);
};

const toggleCompletedSection = () => {
    isCompletedSectionOpen.value = !isCompletedSectionOpen.value;
};

const props = defineProps<{
    achievements: Array<Achievement>;
    flash?: any;
}>();

onMounted(() => {
    const savedCompletedSectionState = readCompletedSectionOpenState();

    if (savedCompletedSectionState !== null) {
        isCompletedSectionOpen.value = savedCompletedSectionState;
    }

    updateAchievementChangeMarkers(props.achievements);
});

watch(isCompletedSectionOpen, (isOpen) => {
    writeCompletedSectionOpenState(isOpen);
});

watch(
    () => props.achievements,
    (nextAchievements) => {
        updateAchievementChangeMarkers(nextAchievements);
    },
    { deep: true },
);

console.log("Achievements Props:", props.achievements);

// Group achievements by completion status and sort by progress
const completedAchievements = props.achievements
    .filter((a) => a.completed)
    .sort(
        (a, b) =>
            new Date(b.earnedAt).getTime() - new Date(a.earnedAt).getTime(),
    ); // Most recently earned first

const inProgressAchievements = props.achievements
    .filter((a) => !a.completed && !a.isLocked)
    .sort((a, b) => b.progressPercentage - a.progressPercentage); // Highest progress first

const lockedAchievements = props.achievements
    .filter((a) => !a.completed && a.isLocked)
    .sort((a, b) => a.title.localeCompare(b.title)); // Alphabetical order

// Get achievement category icon
const getCategoryIcon = (achievementType: string) => {
    switch (achievementType) {
        case "first_catch":
            return Target;
        case "species_diversity":
            return Star;
        case "rare_collector":
            return Crown;
        case "point_accumulator":
            return Trophy;
        case "streak_master":
            return Zap;
        case "social_hunter":
            return Users;
        case "speed_hunter":
            return Clock;
        default:
            return Award;
    }
};

// Get achievement rarity color
const getRarityColor = (achievementType: string) => {
    switch (achievementType) {
        case "first_catch":
            return "text-green-600";
        case "species_diversity":
            return "text-blue-600";
        case "rare_collector":
            return "text-purple-600";
        case "point_accumulator":
            return "text-yellow-600";
        case "streak_master":
            return "text-red-600";
        case "social_hunter":
            return "text-pink-600";
        case "speed_hunter":
            return "text-indigo-600";
        default:
            return "text-gray-600";
    }
};

// Get achievement background color
const getRarityBg = (achievementType: string) => {
    switch (achievementType) {
        case "first_catch":
            return "bg-green-50 border-green-200";
        case "species_diversity":
            return "bg-blue-50 border-blue-200";
        case "rare_collector":
            return "bg-purple-50 border-purple-200";
        case "point_accumulator":
            return "bg-yellow-50 border-yellow-200";
        case "streak_master":
            return "bg-red-50 border-red-200";
        case "social_hunter":
            return "bg-pink-50 border-pink-200";
        case "speed_hunter":
            return "bg-indigo-50 border-indigo-200";
        default:
            return "bg-gray-50 border-gray-200";
    }
};

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
    });
};

const hasProgressItem = (achievement: Achievement, item: string) => {
    return achievement.progressDetail?.currentProgress.includes(item) ?? false;
};
</script>

<template>
    <CatchEmAllLayout
        title="Achievements"
        subtitle="Your progress and unlocks"
        class="select-none"
        :flash="flash"
        icon="gem"
    >
        <!-- Stats Overview -->
        <Card class="bg-gray-800 border border-gray-700 shadow-sm">
            <template #content>
                <div class="grid grid-cols-3 gap-4">
                    <div
                        class="text-center pt-4 pb-4 p-0.5 bg-green-900/20 rounded-lg border border-green-700 achievement-icon"
                    >
                        <CheckCircle
                            class="w-8 h-8 mx-auto mb-2 text-green-600"
                        />
                        <div class="text-2xl font-bold text-green-400">
                            {{ completedAchievements.length }}
                        </div>
                        <div class="text-sm text-green-300">Completed</div>
                    </div>
                    <div
                        class="text-center pt-4 pb-4 p-0.5 bg-blue-900/20 rounded-lg border border-blue-700 achievement-icon"
                    >
                        <Clock class="w-8 h-8 mx-auto mb-2 text-blue-600" />
                        <div class="text-2xl font-bold text-blue-400">
                            {{ inProgressAchievements.length }}
                        </div>
                        <div class="text-sm text-blue-300">In Progress</div>
                    </div>
                    <div
                        class="text-center pt-4 pb-4 p-0.5 bg-gray-700/50 rounded-lg border border-gray-600 achievement-icon"
                    >
                        <Circle class="w-8 h-8 mx-auto mb-2 text-gray-400" />
                        <div class="text-2xl font-bold text-gray-300">
                            {{ lockedAchievements.length }}
                        </div>
                        <div class="text-sm text-gray-400">Locked</div>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Completed Achievements -->
        <div v-if="completedAchievements.length > 0">
            <button
                type="button"
                class="w-full mb-3 flex items-center justify-between text-left"
                @click="toggleCompletedSection"
            >
                <h3 class="text-lg font-bold text-gray-100 flex items-center">
                    <CheckCircle class="w-6 h-6 mr-2 text-green-400" />
                    Completed ({{ completedAchievements.length }})
                </h3>
                <span
                    :class="[
                        'text-gray-400 text-xs leading-none transition-transform duration-300 ease-out',
                        isCompletedSectionOpen ? 'rotate-180' : '',
                    ]"
                    >▼</span
                >
            </button>
            <div v-show="isCompletedSectionOpen" class="space-y-3 mb-6">
                <Card
                    v-for="achievement in completedAchievements"
                    :key="achievement.id"
                    :class="[
                        'bg-white shadow-sm border border-gray-700',
                        achievement.isOptional
                            ? 'optional-achievement-card'
                            : '',
                        achievement.expandable ? 'cursor-pointer' : '',
                    ]"
                    @click="
                        achievement.expandable
                            ? toggleAchievementExpansion(achievement.id)
                            : null
                    "
                >
                    <template #content>
                        <div class="relative">
                            <span
                                v-if="achievement.isOptional"
                                class="optional-flag"
                            >
                                ◌ Optional
                            </span>
                            <div
                                :class="[
                                    'relative flex items-center space-x-4 p-2 rounded-lg',
                                    isNewlyCompleted(achievement.id)
                                        ? 'achievement-newly-completed'
                                        : '',
                                    hasProgressUpdate(achievement.id)
                                        ? 'achievement-progress-updated'
                                        : '',
                                ]"
                            >
                                <!-- Achievement Icon -->
                                <div
                                    class="w-14 h-14 rounded-full flex items-center justify-center border-2 border-green-300 bg-green-100"
                                >
                                    <component
                                        :is="
                                            getCategoryIcon(
                                                achievement.achievement,
                                            )
                                        "
                                        class="w-7 h-7 text-green-600"
                                    />
                                </div>

                                <!-- Achievement Info -->
                                <div class="flex-1">
                                    <div
                                        class="flex items-center space-x-2 mb-1"
                                    >
                                        <h4 class="font-semibold text-gray-200">
                                            {{ achievement.title }}
                                        </h4>
                                        <Star
                                            class="w-5 h-5 text-yellow-500 fill-current"
                                        />
                                        <span
                                            v-if="achievement.expandable"
                                            :class="[
                                                'ml-auto text-gray-400 text-xs leading-none transition-transform duration-300 ease-out',
                                                extendedAchievements.includes(
                                                    achievement.id,
                                                )
                                                    ? 'rotate-180'
                                                    : '',
                                            ]"
                                            >▼</span
                                        >
                                    </div>
                                    <div
                                        :class="[
                                            'achievement-text-wrapper mb-2',
                                            achievement.expandable &&
                                            extendedAchievements.includes(
                                                achievement.id,
                                            )
                                                ? 'is-expanded'
                                                : 'is-collapsed',
                                        ]"
                                    >
                                        <p
                                            :class="[
                                                'text-sm text-gray-300 text-justify',
                                                achievement.expandable &&
                                                !extendedAchievements.includes(
                                                    achievement.id,
                                                )
                                                    ? 'line-clamp-2'
                                                    : '',
                                            ]"
                                        >
                                            {{ achievement.description }}
                                        </p>
                                    </div>
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <div
                                            class="text-xs text-green-600 font-medium"
                                        >
                                            ✅ Completed on
                                            {{
                                                formatDate(achievement.earnedAt)
                                            }}
                                        </div>
                                        <div
                                            class="text-sm font-bold text-green-600"
                                        >
                                            {{
                                                achievement.maxProgress > 1
                                                    ? `${achievement.progress}/${achievement.maxProgress}`
                                                    : "100%"
                                            }}
                                        </div>
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
                <Clock class="w-6 h-6 mr-2 text-blue-600" />
                In Progress ({{ inProgressAchievements.length }})
            </h3>
            <div class="space-y-3 mb-6">
                <Card
                    v-for="achievement in inProgressAchievements"
                    :key="achievement.id"
                    :class="[
                        'bg-white shadow-sm border border-gray-700 cursor-pointer',
                        achievement.isOptional
                            ? 'optional-achievement-card'
                            : '',
                    ]"
                    @click="
                        achievement.expandable
                            ? toggleAchievementExpansion(achievement.id)
                            : null
                    "
                >
                    <template #content>
                        <div class="relative">
                            <span
                                v-if="achievement.isOptional"
                                class="optional-flag"
                            >
                                ◌ Optional
                            </span>
                            <div
                                :class="[
                                    'relative flex items-center space-x-4 p-2 rounded-lg',
                                    isNewlyCompleted(achievement.id)
                                        ? 'achievement-newly-completed'
                                        : '',
                                    hasProgressUpdate(achievement.id)
                                        ? 'achievement-progress-updated'
                                        : '',
                                ]"
                            >
                                <!-- Achievement Icon -->
                                <div
                                    class="w-14 h-14 rounded-full flex items-center justify-center border-2"
                                    :class="'border-blue-300 bg-blue-100'"
                                >
                                    <component
                                        :is="
                                            getCategoryIcon(
                                                achievement.achievement,
                                            )
                                        "
                                        class="w-7 h-7 text-blue-600"
                                    />
                                </div>

                                <!-- Achievement Info -->
                                <div class="flex-1">
                                    <div
                                        class="flex items-center space-x-2 mb-1"
                                    >
                                        <h4 class="font-semibold text-gray-200">
                                            {{ achievement.title }}
                                        </h4>
                                        <span
                                            v-if="achievement.expandable"
                                            :class="[
                                                'ml-auto text-gray-400 text-xs leading-none transition-transform duration-300 ease-out',
                                                extendedAchievements.includes(
                                                    achievement.id,
                                                )
                                                    ? 'rotate-180'
                                                    : '',
                                            ]"
                                            >▼</span
                                        >
                                    </div>
                                    <div
                                        :class="[
                                            'achievement-text-wrapper mb-2',
                                            achievement.expandable &&
                                            extendedAchievements.includes(
                                                achievement.id,
                                            )
                                                ? 'is-expanded'
                                                : 'is-collapsed',
                                        ]"
                                    >
                                        <p
                                            :class="[
                                                'text-sm text-gray-300 text-justify',
                                                achievement.expandable &&
                                                !extendedAchievements.includes(
                                                    achievement.id,
                                                )
                                                    ? 'line-clamp-2'
                                                    : '',
                                            ]"
                                        >
                                            {{ achievement.task }}
                                        </p>
                                    </div>

                                    <!-- Progress Bar -->
                                    <div class="space-y-2">
                                        <div
                                            class="flex justify-between text-sm"
                                        >
                                            <span class="text-gray-300"
                                                >Progress</span
                                            >
                                            <span
                                                class="font-medium text-blue-500"
                                            >
                                                {{ achievement.progress }}/{{
                                                    achievement.maxProgress
                                                }}
                                                ({{
                                                    achievement.progressPercentage
                                                }}%)
                                            </span>
                                        </div>
                                        <div
                                            class="h-3 bg-gray-200 rounded-full overflow-hidden"
                                        >
                                            <div
                                                class="h-full bg-gradient-to-r from-blue-500 to-blue-600 rounded-full transition-all duration-500"
                                                :style="`width: ${achievement.progressPercentage}%`"
                                            ></div>
                                        </div>
                                    </div>

                                    <div
                                        v-if="
                                            achievement.expandable &&
                                            achievement.progressDetail
                                                ?.totalProgress.length
                                        "
                                        :class="[
                                            'progress-detail-wrapper',
                                            extendedAchievements.includes(
                                                achievement.id,
                                            )
                                                ? 'is-open'
                                                : 'is-closed',
                                        ]"
                                    >
                                        <div class="mt-4">
                                            <div
                                                class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2"
                                            >
                                                Progress Overview
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <div
                                                    v-for="progressItem in [
                                                        ...achievement
                                                            .progressDetail
                                                            .totalProgress,
                                                    ].sort((a, b) =>
                                                        a.localeCompare(b),
                                                    )"
                                                    :key="progressItem"
                                                    :class="[
                                                        'rounded-md border px-2 py-1 text-xs font-medium transition-colors',
                                                        achievement.progressDetail.currentProgress.includes(
                                                            progressItem,
                                                        )
                                                            ? 'border-green-500 bg-green-100 text-green-700'
                                                            : 'border-red-500 bg-red-100 text-red-700',
                                                    ]"
                                                >
                                                    {{ progressItem }}
                                                </div>
                                            </div>
                                        </div>
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
                Locked ({{ lockedAchievements.length }}) (Hidden:
                {{ lockedAchievements.filter((a) => a.hiddenByLock).length }}
                )
            </h3>
            <div class="space-y-3">
                <Card
                    v-for="achievement in lockedAchievements"
                    :hidden="achievement.hiddenByLock"
                    :key="achievement.id"
                    :class="[
                        'bg-white shadow-sm opacity-75 border border-gray-700',
                        achievement.isOptional
                            ? 'optional-achievement-card'
                            : '',
                    ]"
                >
                    <template #content>
                        <div class="relative">
                            <span
                                v-if="achievement.isOptional"
                                class="optional-flag"
                            >
                                ◌ Optional
                            </span>
                            <div
                                :class="[
                                    'relative flex items-center space-x-4 p-2 rounded-lg',
                                    isNewlyCompleted(achievement.id)
                                        ? 'achievement-newly-completed'
                                        : '',
                                    hasProgressUpdate(achievement.id)
                                        ? 'achievement-progress-updated'
                                        : '',
                                ]"
                            >
                                <!-- Achievement Icon -->
                                <div
                                    class="w-14 h-14 rounded-full flex items-center justify-center border-2 border-gray-300 bg-gray-100"
                                >
                                    <component
                                        :is="
                                            getCategoryIcon(
                                                achievement.achievement,
                                            )
                                        "
                                        class="w-7 h-7 text-gray-400"
                                    />
                                </div>

                                <!-- Achievement Info -->
                                <div class="flex-1">
                                    <h4
                                        class="font-semibold text-gray-200 mb-1"
                                    >
                                        {{ achievement.title }}
                                    </h4>
                                    <p class="text-sm text-gray-300 mb-2">
                                        {{ achievement.task }}
                                    </p>
                                    <div
                                        class="text-xs text-gray-400 font-medium"
                                    >
                                        🔒 You need to do more tasks to unlock
                                        this achievement!
                                    </div>
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
            <h3 class="text-xl font-medium text-gray-200 mb-2">
                No achievements available
            </h3>
            <p class="text-gray-300">
                Start catching fursuiters to unlock achievements!
            </p>
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

.achievement-text-wrapper {
    overflow: hidden;
    transition:
        max-height 260ms ease,
        opacity 200ms ease;
    will-change: max-height, opacity;
}

.achievement-text-wrapper.is-collapsed {
    max-height: 3.2em;
    opacity: 0.96;
}

.achievement-text-wrapper.is-expanded {
    max-height: 20em;
    opacity: 1;
}

.progress-detail-wrapper {
    overflow: hidden;
    max-height: 0;
    opacity: 0;
    transform: translateY(-4px);
    transition:
        max-height 320ms ease,
        opacity 220ms ease,
        transform 260ms ease;
    transition-delay: 120ms;
    will-change: max-height, opacity, transform;
}

.progress-detail-wrapper.is-open {
    max-height: 160rem;
    opacity: 1;
    transform: translateY(0);
}

.progress-detail-wrapper.is-closed {
    max-height: 0;
    opacity: 0;
    transform: translateY(-4px);
}

.achievement-newly-completed {
    position: relative;
    overflow: hidden;
    animation: achievement-glow 1200ms ease-out;
}

.achievement-newly-completed::after {
    content: "";
    position: absolute;
    top: 0;
    left: -160%;
    width: 70%;
    height: 100%;
    background: linear-gradient(
        105deg,
        rgba(255, 255, 255, 0),
        rgba(255, 255, 255, 0.5),
        rgba(255, 255, 255, 0)
    );
    animation: achievement-shine 1200ms ease-out;
}

.achievement-progress-updated {
    animation: achievement-border-pulse 1800ms ease-out 2;
}

.optional-flag {
    position: absolute;
    top: -0.85rem;
    right: -0.85rem;
    font-size: 0.65rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: rgba(148, 163, 184, 0.9);
    opacity: 0.6;
    background: rgba(15, 23, 42, 0.2);
    border: 1px solid rgba(148, 163, 184, 0.35);
    border-radius: 999px;
    padding: 0.05rem 0.45rem;
    pointer-events: none;
}

@keyframes achievement-shine {
    0% {
        left: -160%;
    }
    100% {
        left: 130%;
    }
}

@keyframes achievement-glow {
    0% {
        box-shadow: 0 0 0 rgba(16, 185, 129, 0);
    }
    35% {
        box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.45);
    }
    100% {
        box-shadow: 0 0 0 rgba(16, 185, 129, 0);
    }
}

@keyframes achievement-border-pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(59, 130, 246, 0);
    }
    35% {
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(59, 130, 246, 0);
    }
}
</style>
