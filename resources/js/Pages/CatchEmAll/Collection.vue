<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from "vue";
import { router } from "@inertiajs/vue3";
import CatchEmAllLayout from "@/Layouts/CatchEmAllLayout.vue";
import Card from "primevue/card";
import Dropdown from "primevue/dropdown";
import {
    BookOpen,
    Star,
    Gem,
    Sparkles,
    Crown,
    Filter,
    Grid3X3,
    List,
    Info,
} from "lucide-vue-next";
import GalleryItem from "@/Components/Gallery/GalleryItem.vue";

const props = defineProps<{
    collection: {
        suits: Array<{
            species: string;
            rarity: {
                level: string;
                label: string;
                color: string;
                icon: string;
            };
            count: number;
            gallery: {
                id: number;
                name: string;
                species: string;
                image: string;
                scoring: number;
            };
        }>;
        species: Record<string, number>;
        totalCatches: number;
    };
    eventsWithEntries: Array<any>;
    selectedEvent?: string | null;
    isGlobal: boolean;
    flash?: any;
}>();

console.log(
    "[Collection] Props:",
    Object.keys(props.collection.species).length
);

// Debug logs for initial props
// console.log('[Collection] Received props:', {
//     collectionExists: !!props.collection,
//     speciesCount: props.collection?.species?.length,
//     totalSpecies: props.collection?.totalSpecies,
//     totalCatches: props.collection?.totalCatches,
//     eventsCount: props.eventsWithEntries?.length,
//     selectedEvent: props.selectedEvent,
//     isGlobal: props.isGlobal
// })

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
    console.log("[Collection] Event changed to:", selectedEventValue.value);
    router.get(
        route("catch-em-all.collection"),
        {
            event: selectedEventValue.value,
        },
        {
            preserveState: false,
            replace: true,
        }
    );
};

// Monitor collection changes
watch(
    () => props.collection,
    (newVal, oldVal) => {
        // console.log('[Collection] Collection updated:', {
        //     hasOldData: !!oldVal,
        //     hasNewData: !!newVal,
        //     oldSpeciesCount: oldVal?.species?.length,
        //     newSpeciesCount: newVal?.species?.length,
        //     newTotalSpecies: newVal?.totalSpecies,
        //     newTotalCatches: newVal?.totalCatches
        // })
    },
    { deep: true }
);

// View mode toggle
const viewMode = ref<"grid" | "list">("grid");

onMounted(() => {
    const savedViewMode = localStorage.getItem("catch-em-all-collection-view-mode");
    if (savedViewMode && (savedViewMode === "grid" || savedViewMode === "list")) {
        viewMode.value = savedViewMode;
    }
});

watch(viewMode, (newMode) => {
    localStorage.setItem("catch-em-all-collection-view-mode", newMode);
});

// Rarity filter
const selectedRarity = ref<string>("all");
const rarityOptions = [
    { label: "All Rarities", value: "all" },
    { label: "Common", value: "common" },
    { label: "Uncommon", value: "uncommon" },
    { label: "Rare", value: "rare" },
    { label: "Epic", value: "epic" },
    { label: "Legendary", value: "legendary" },
];

// Counter visibility toggle
const showCounters = ref(true);
const showTooltip = ref(false);

// Handle click outside to hide tooltip
const handleClickOutside = (event: Event) => {
    const target = event.target as HTMLElement;
    const tooltipContainer = target.closest('.tooltip-container');
    if (!tooltipContainer && showTooltip.value) {
        showTooltip.value = false;
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});

// Load counter preference from localStorage
onMounted(() => {
    const savedCounterPreference = localStorage.getItem("catch-em-all-show-counters");
    if (savedCounterPreference !== null) {
        showCounters.value = JSON.parse(savedCounterPreference);
    }
});

watch(showCounters, (newValue) => {
    localStorage.setItem("catch-em-all-show-counters", JSON.stringify(newValue));
});

// Filter collection by rarity
const filteredCollection = computed(() => {
    //TODO: figure out if having props.collection.species "isEmpty" check is necessary

    // Return empty array if collection or species is not loaded yet
    if (!props.collection?.suits) {
        return [];
    }
    if (selectedRarity.value === "all") {
        return props.collection.suits;
    }
    return props.collection.suits.filter(
        (suit) => suit.rarity.level === selectedRarity.value
    );
});

// Group species by rarity
const collectionByRarity = computed(() => {
    const grouped = {
        legendary: [],
        epic: [],
        rare: [],
        uncommon: [],
        common: [],
    };

    props.collection.suits.forEach((suit) => {
        const rarity = suit.rarity.level;
        if (grouped[rarity]) {
            grouped[rarity].push(suit);
        }
    });

    return grouped;
});

// Get rarity icon
const getRarityIcon = (rarity: string) => {
    switch (rarity) {
        case "legendary":
            return Crown;
        case "epic":
            return Gem;
        case "rare":
            return Sparkles;
        case "uncommon":
            return Star;
        case "common":
            return BookOpen;
        default:
            return Star;
    }
};

// Get rarity stats
const rarityStats = computed(() => {
    const stats = {
        legendary: 0,
        epic: 0,
        rare: 0,
        uncommon: 0,
        common: 0,
    };

    props.collection.suits.forEach((suit) => {
        const rarity = suit.rarity.level;
        if (stats[rarity] !== undefined) {
            stats[rarity] += 1;
        }
    });

    return stats;
});

const getRarityBgColor = (textColor: string) => {
    const colorMap: Record<string, string> = {
        "text-yellow-600": "bg-yellow-600",
        "text-purple-600": "bg-purple-600",
        "text-blue-600": "bg-blue-600",
        "text-green-600": "bg-green-600",
        "text-gray-600": "bg-gray-500",
    };
    return colorMap[textColor] || "bg-gray-500";
};
</script>

<template>
    <CatchEmAllLayout
        title="Collection"
        subtitle="Your fursuiter collection"
        :flash="flash"
        icon="library"
    >
        <!-- Collection Stats -->
        <Card class="bg-white shadow-sm border border-gray-700">
            <template #content>
                <div class="text-center mb-4">
                    <h2 class="text-xl font-bold text-gray-200">
                        Your Collection
                    </h2>
                    <p
                        class="text-sm text-gray-300"
                        v-if="collection?.species !== undefined"
                    >
                        {{
                            Object.keys(props.collection.species).length
                        }}
                        unique species • {{ collection.totalCatches }} total
                        catches
                    </p>
                    <p class="text-sm text-gray-300" v-else>
                        Loading collection...
                    </p>
                </div>

                <!-- Rarity Distribution -->
                <div class="grid grid-cols-5 gap-2 mb-4">
                    <div
                        class="icon-box text-center bg-yellow-50 rounded-lg border border-yellow-200 rarity-tile"
                    >
                        <Crown class="w-5 h-5 mx-auto mb-1 text-yellow-800" />
                        <div class="text-sm font-bold text-yellow-800">
                            {{ rarityStats.legendary }}
                        </div>
                        <div class="icon-text text-yellow-800">Legendary</div>
                    </div>
                    <div
                        class="icon-box text-center bg-purple-50 rounded-lg border border-purple-200 rarity-tile"
                    >
                        <Gem class="w-5 h-5 mx-auto mb-1 text-purple-800" />
                        <div class="text-sm font-bold text-purple-800">
                            {{ rarityStats.epic }}
                        </div>
                        <div class="icon-text text-purple-800">Epic</div>
                    </div>
                    <div
                        class="icon-box text-center bg-blue-50 rounded-lg border border-blue-200 rarity-tile"
                    >
                        <Sparkles class="w-5 h-5 mx-auto mb-1 text-blue-800" />
                        <div class="text-sm font-bold text-blue-800">
                            {{ rarityStats.rare }}
                        </div>
                        <div class="icon-text text-blue-800">Rare</div>
                    </div>
                    <div
                        class="icon-box text-center bg-green-50 rounded-lg border border-green-200 rarity-tile"
                    >
                        <Star class="w-5 h-5 mx-auto mb-1 text-green-800" />
                        <div class="text-sm font-bold text-green-800">
                            {{ rarityStats.uncommon }}
                        </div>
                        <div class="icon-text text-green-800">Uncommon</div>
                    </div>
                    <div
                        class="icon-box text-center bg-gray-50 rounded-lg border border-gray-200 rarity-tile"
                    >
                        <BookOpen class="w-5 h-5 mx-auto mb-1 text-gray-800" />
                        <div class="text-sm font-bold text-gray-800">
                            {{ rarityStats.common }}
                        </div>
                        <div class="icon-text text-gray-800">Common</div>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Filters and Controls -->
        <Card class="bg-white shadow-sm border border-gray-700">
            <template #content>
                <div
                    class="flex flex-col gap-4 items-start sm:items-center justify-between"
                    :class="
                        eventOptions.length > 2 ? 'sm:flex-row' : 'xs:flex-row'
                    "
                >
                    <!-- Event Filter -->
                    <div v-if="eventOptions.length > 2" class="flex-1 min-w-20">
                        <label
                            class="block text-sm font-medium text-gray-700 mb-2"
                            >Event:</label
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

                    <!-- Rarity Filter -->
                    <div class="flex-1 min-w-20">
                        <label
                            class="block text-sm font-medium text-gray-300 mb-2"
                            >Rarity:</label
                        >
                        <Dropdown
                            v-model="selectedRarity"
                            :options="rarityOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            fluid
                        />
                    </div>
                    <!-- View Mode Toggle -->
                    <div class="flex-shrink-0">
                        <label
                            class="block text-sm font-medium text-gray-300 mb-2"
                            >View:</label
                        >
                        <div
                            class="flex rounded-lg border border-gray-300 overflow-hidden"
                        >
                            <button
                                @click="viewMode = 'list'"
                                class="px-3 py-2 transition-colors"
                                :class="
                                    viewMode === 'list'
                                        ? 'bg-blue-500 text-white'
                                        : 'bg-white text-gray-600 hover:bg-gray-50'
                                "
                            >
                                <List class="w-5 h-5" />
                            </button>
                            <button
                                @click="viewMode = 'grid'"
                                class="px-3 py-2 transition-colors"
                                :class="
                                    viewMode === 'grid'
                                        ? 'bg-blue-500 text-white'
                                        : 'bg-white text-gray-600 hover:bg-gray-50'
                                "
                            >
                                <Grid3X3 class="w-5 h-5" />
                            </button>
                        </div>
                    </div>

                    <!-- Counter Toggle -->
                    <div class="flex-shrink-0 relative tooltip-container">
                        <label
                            class="block text-sm font-medium text-gray-300 mb-2"
                            >Counters:</label
                        >
                        <div class="relative">
                            <button
                                @click="showCounters = !showCounters"
                                class="px-3 py-2 rounded-lg border border-gray-300 transition-colors"
                                :class="
                                    showCounters
                                        ? 'bg-blue-500 text-white'
                                        : 'bg-white text-gray-600 hover:bg-gray-50'
                                "
                                :title="showCounters 
                                    ? 'Hide scoring numbers on fursuit cards' 
                                    : 'Show scoring numbers on fursuit cards'"
                            >
                                <span class="text-sm font-medium">
                                    {{ showCounters ? 'Hide' : 'Show' }}
                                </span>
                            </button>
                            
                            <!-- Mobile Tooltip Info Button -->
                            <button
                                @click="showTooltip = !showTooltip"
                                class="absolute -top-1 -right-1 w-4 h-4 bg-gray-500 hover:bg-gray-600 text-white rounded-full flex items-center justify-center md:hidden"
                                type="button"
                            >
                                <Info class="w-2.5 h-2.5" />
                            </button>
                        </div>
                        
                        <!-- Mobile Tooltip -->
                        <div 
                            v-if="showTooltip"
                            class="absolute top-16 right-0 bg-gray-800 text-white text-xs px-2 py-1 rounded shadow-lg z-10 whitespace-nowrap md:hidden"
                        >
                            Shows total catches made by all players
                            <div class="absolute -top-1 right-2 w-0 h-0 border-l-4 border-r-4 border-b-4 border-l-transparent border-r-transparent border-b-gray-800"></div>
                        </div>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Collection Display -->
        <Card class="bg-white shadow-sm border border-gray-700">
            <template #content>
                <!-- Grid View -->
                <div
                    v-if="viewMode === 'grid'"
                    class="grid grid-cols-2 lg:grid-cols-3 gap-6 mb-8"
                >
                    <div
                        v-for="fursuit in filteredCollection"
                        :key="fursuit.gallery.id"
                        class="cursor-pointer transform transition-transform hover:scale-105"
                    >
                        <GalleryItem :fursuit="fursuit.gallery" :rarity="fursuit.rarity" :hideCount="!showCounters" />
                    </div>
                </div>

                <!-- List View -->
                <div v-else class="space-y-2">
                    <div
                        v-for="fursuit in filteredCollection"
                        :key="fursuit.gallery.id"
                        class="flex items-center p-3 bg-gray-800 rounded-lg shadow-sm border border-gray-700"
                    >
                        <img
                            :src="fursuit.gallery.image"
                            :alt="fursuit.gallery.name"
                            class="w-12 h-12 rounded-md object-cover mr-4"
                        />
                        <div class="flex-1">
                            <h4 class="font-bold text-base text-gray-200">
                                {{ fursuit.gallery.name }}
                            </h4>
                            <p class="text-sm text-gray-400">
                                {{ fursuit.species }}
                            </p>
                        </div>
                        <div class="text-center mx-4">
                            <span
                                class="px-2 py-1 text-xs font-semibold text-white rounded-full whitespace-nowrap"
                                :class="getRarityBgColor(fursuit.rarity.color)"
                                >{{ fursuit.rarity.label }}</span
                            >
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div
                    v-if="filteredCollection.length === 0"
                    class="text-center py-12"
                >
                    <Filter
                        v-if="selectedRarity !== 'all'"
                        class="w-16 h-16 mx-auto mb-4 text-gray-300"
                    />
                    <h3 class="text-lg font-medium text-gray-200 mb-2">
                        {{
                            selectedRarity !== "all"
                                ? "No species found"
                                : "No collection yet"
                        }}
                    </h3>
                    <p class="text-gray-300">
                        {{
                            selectedRarity !== "all"
                                ? "Try a different rarity filter or start catching more fursuiters!"
                                : "Start catching fursuiters to build your collection!"
                        }}
                    </p>
                </div>
            </template>
        </Card>
    </CatchEmAllLayout>
</template>

<style scoped>
/* Enhanced card styling */
:deep(.p-card) {
    border-radius: 12px !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
    border: 1px solid rgba(0, 0, 0, 0.05) !important;
}

/* Collection item hover effects */
.collection-item {
    transition: all 0.2s ease;
}

.collection-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.icon-text {
    font-size: 0.55rem;
    line-height: 0.75rem;
}

.icon-box {
    padding-top: 0.5rem;
    padding-bottom: 0.5rem;
}

/* Grid view animations */
@keyframes sparkle {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.7;
        transform: scale(1.1);
    }
}

.legendary-sparkle {
    animation: sparkle 2s ease-in-out infinite;
}
</style>
