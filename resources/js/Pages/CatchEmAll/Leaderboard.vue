<script setup lang="ts">
import CatchEmAllLayout from "@/Layouts/CatchEmAllLayout.vue";
import { router } from "@inertiajs/vue3";
import { Award, Crown, Star, TrendingUp, Trophy } from "lucide-vue-next";
import Card from "primevue/card";
import Dropdown from "primevue/dropdown";
import { computed, ref } from "vue";

const props = defineProps<{
    leaderboard: Array<any>;
    eventsWithEntries: Array<any>;
    selectedEvent?: string | null;
    isGlobal: boolean;
    flash?: any;
}>();

// Event selection
const eventOptions = computed(() => [
    { label: "Global (All-Time)", value: "global" },
    ...props.eventsWithEntries.map((event) => ({
        label: `${event.name} (${new Date(event.starts_at).getFullYear()})`,
        value: event.id.toString(),
    })),
]);

const selectedEventValue = ref(props.selectedEvent || "global");

const onEventChange = () => {
    router.get(
        route("catch-em-all.leaderboard"),
        {
            event: selectedEventValue.value,
        },
        {
            preserveState: false,
            replace: true,
        }
    );
};

const getRankIcon = (rank: number) => {
    if (rank === 1) return Crown;
    if (rank === 2) return Trophy;
    if (rank === 3) return Award;
    if (rank <= 10) return Star;
    return TrendingUp;
};

const getRankColor = (rank: number) => {
    if (rank === 1) return "text-yellow-500";
    if (rank === 2) return "text-gray-400";
    if (rank === 3) return "text-amber-600";
    if (rank <= 10) return "text-blue-500";
    return "text-gray-500";
};

// Different crown/medal icons for podium positions
const getPodiumIcon = (rank: number) => {
    if (rank === 1) return "👑"; // Crown for first place
    if (rank === 2) return "🥈"; // Silver medal
    if (rank === 3) return "🥉"; // Bronze medal
    return null;
};
</script>

<template>
    <CatchEmAllLayout
        title="Leaderboard"
        subtitle="Top Fursuit Catch em Alls"
        :flash="flash"
        icon="medal"
    >
        <!-- Event Filter -->
        <Card v-if="eventOptions.length > 2" class="bg-gray-800 border border-gray-700 shadow-sm">
            <template #content>
                <div class="space-y-3">
                    <label class="block text-sm font-medium text-gray-300"
                        >Event Filter:</label
                    >
                    <Dropdown
                        v-model="selectedEventValue"
                        :options="eventOptions"
                        optionLabel="label"
                        optionValue="value"
                        class="w-full"
                        @change="onEventChange"
                        fluid
                    />
                </div>
            </template>
        </Card>

        <!-- Leaderboard -->
        <Card class="bg-gray-800 border border-gray-700 shadow-sm">
            <template #content>
                <div class="text-center mb-6">
                    <div
                        class="w-16 h-16 mx-auto mb-3 bg-gradient-to-br from-yellow-500 to-orange-600 rounded-full flex items-center justify-center"
                    >
                        <Trophy class="w-8 h-8 text-white" />
                    </div>
                    <h2 class="text-xl font-bold text-gray-100">Leaderboard</h2>
                    <p class="text-sm text-gray-300">
                        {{
                            props.isGlobal
                                ? "All-time champions"
                                : "Event champions"
                        }}
                        • {{ props.leaderboard.length }} hunters
                    </p>
                </div>

                <div class="space-y-3">
                    <!-- Top 3 Podium -->
                    <div v-if="leaderboard.length >= 3" class="mb-6">
                        <div
                            class="flex items-end justify-center space-x-4 mb-4"
                        >
                            <!-- 2nd Place -->
                            <div class="flex flex-col items-center">
                                <div
                                    class="w-16 h-16 rounded-full bg-gradient-to-br from-gray-300 to-gray-500 flex items-center justify-center mb-2 border-4 border-gray-200"
                                >
                                    <Crown class="w-8 h-8 text-white" />
                                </div>
                                <div class="text-center">
                                    <div
                                        class="text-sm font-bold text-gray-100"
                                    >
                                        {{ leaderboard[1]?.name }}
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        {{ leaderboard[1]?.catches }} catches
                                    </div>
                                </div>
                            </div>

                            <!-- 1st Place -->
                            <div class="flex flex-col items-center -mt-4">
                                <div
                                    class="w-20 h-20 rounded-full bg-gradient-to-br from-yellow-400 to-yellow-600 flex items-center justify-center mb-2 border-4 border-yellow-200 shadow-lg"
                                >
                                    <Crown class="w-10 h-10 text-white" />
                                </div>
                                <div class="text-center">
                                    <div
                                        class="text-base font-bold text-yellow-400"
                                    >
                                        {{ leaderboard[0]?.name }}
                                    </div>
                                    <div class="text-sm text-yellow-300">
                                        {{ leaderboard[0]?.catches }} catches
                                    </div>
                                </div>
                            </div>

                            <!-- 3rd Place -->
                            <div class="flex flex-col items-center">
                                <div
                                    class="w-16 h-16 rounded-full bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center mb-2 border-4 border-amber-200"
                                >
                                    <Crown class="w-8 h-8 text-white" />
                                </div>
                                <div class="text-center">
                                    <div
                                        class="text-sm font-bold text-gray-100"
                                    >
                                        {{ leaderboard[2]?.name }}
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        {{ leaderboard[2]?.catches }} catches
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Full Leaderboard List -->
                    <div class="space-y-2">
                        <div
                            v-for="(player, index) in leaderboard"
                            :key="player.id"
                            class="flex items-center justify-between p-4 rounded-lg border transition-all hover:shadow-md"
                            :class="[
                                index < 3
                                    ? 'bg-gradient-to-r from-yellow-900/20 to-orange-900/20 border-yellow-700'
                                    : 'bg-gray-700/50 border-gray-600',
                                index === 0 ? 'ring-2 ring-yellow-300' : '',
                            ]"
                        >
                            <div class="flex items-center space-x-4">
                                <!-- Rank Badge -->
                                <div
                                    class="w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg"
                                    :class="[
                                        index === 0
                                            ? 'bg-yellow-900/30 text-yellow-400'
                                            : index === 1
                                            ? 'bg-gray-700 text-gray-300'
                                            : index === 2
                                            ? 'bg-amber-900/30 text-amber-400'
                                            : 'bg-blue-900/30 text-blue-400',
                                    ]"
                                >
                                    <component
                                        :is="getRankIcon(player.rank)"
                                        class="w-6 h-6"
                                        :class="getRankColor(player.rank)"
                                    />
                                </div>

                                <!-- Player Info -->
                                <div>
                                    <div class="flex items-center space-x-2">
                                        <div
                                            class="font-semibold text-gray-100"
                                        >
                                            {{ player.name }}
                                        </div>
                                        <div v-if="index < 3" class="text-lg">
                                            {{ getPodiumIcon(player.rank) }}
                                        </div>
                                    </div>
                                    <div class="text-sm text-gray-400">
                                        Rank #{{ player.rank }} •
                                        {{ player.catches }} catches
                                    </div>
                                </div>
                            </div>

                            <!-- Points -->
                            <div class="text-right">
                                <div
                                    class="font-bold text-xl"
                                    :class="
                                        index < 3
                                            ? 'text-blue-400'
                                            : 'text-gray-100'
                                    "
                                >
                                    {{ player.catches }}
                                </div>
                                <div class="text-xs text-gray-400">catches</div>
                            </div>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div
                        v-if="leaderboard.length === 0"
                        class="text-center py-8"
                    >
                        <Trophy class="w-16 h-16 mx-auto mb-4 text-gray-300" />
                        <h3 class="text-lg font-medium text-gray-300 mb-2">
                            No hunters yet!
                        </h3>
                        <p class="text-gray-400">
                            Be the first to catch some fursuiters and claim the
                            top spot.
                        </p>
                    </div>
                </div>
            </template>
        </Card>
    </CatchEmAllLayout>
</template>

<style scoped>
/* Enhanced card styling for dark mode */
:deep(.p-card) {
    border-radius: 12px !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3) !important;
    background: transparent !important;
}

/* Podium animation */
@keyframes crown-glow {
    0%,
    100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

.podium-crown {
    animation: crown-glow 2s ease-in-out infinite;
}
</style>
