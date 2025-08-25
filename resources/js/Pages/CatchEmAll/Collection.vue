<script setup lang="ts">
import { ref, computed, watch } from "vue";
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
const viewMode = ref<"grid" | "list">("list");

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
            stats[rarity] += suit.count;
        }
    });

    return stats;
});
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
                </div>
            </template>
        </Card>

        <!-- Collection Display -->
        <Card class="bg-white shadow-sm border border-gray-700">
            <template #content>
                <div
                    class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 mb-8"
                >
                    <div
                        v-for="fursuit in filteredCollection"
                        :key="fursuit.gallery.id"
                        class="cursor-pointer transform transition-transform hover:scale-105"
                    >
                        <GalleryItem :fursuit="fursuit.gallery" />
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
