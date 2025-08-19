<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import CatchEmAllLayout from '@/Layouts/CatchEmAllLayout.vue'
import Card from 'primevue/card'
import Dropdown from 'primevue/dropdown'
import {
    BookOpen,
    Star,
    Gem,
    Sparkles,
    Crown,
    Filter,
    Grid3X3,
    List
} from 'lucide-vue-next'

const props = defineProps<{
    collection: {
        species: Array<any>
        totalSpecies: number
        totalCatches: number
    },
    eventsWithEntries: Array<any>,
    selectedEvent?: string | null,
    isGlobal: boolean,
    flash?: any
}>()

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
    { label: 'Global (All-Time)', value: 'global' },
    ...props.eventsWithEntries.map(event => ({
        label: `${event.name} (${new Date(event.starts_at).getFullYear()})`,
        value: event.id.toString()
    }))
])

const selectedEventValue = ref(props.selectedEvent || 'global')

const onEventChange = () => {
    console.log('[Collection] Event changed to:', selectedEventValue.value)
    router.get(route('catch-em-all.collection'), {
        event: selectedEventValue.value
    }, {
        preserveState: false,
        replace: true
    })
}

// Monitor collection changes
watch(() => props.collection, (newVal, oldVal) => {
    // console.log('[Collection] Collection updated:', {
    //     hasOldData: !!oldVal,
    //     hasNewData: !!newVal,
    //     oldSpeciesCount: oldVal?.species?.length,
    //     newSpeciesCount: newVal?.species?.length,
    //     newTotalSpecies: newVal?.totalSpecies,
    //     newTotalCatches: newVal?.totalCatches
    // })
}, { deep: true })

// View mode toggle
const viewMode = ref<'grid' | 'list'>('list')

// Rarity filter
const selectedRarity = ref<string>('all')
const rarityOptions = [
    { label: 'All Rarities', value: 'all' },
    { label: 'Common', value: 'common' },
    { label: 'Uncommon', value: 'uncommon' },
    { label: 'Rare', value: 'rare' },
    { label: 'Epic', value: 'epic' },
    { label: 'Legendary', value: 'legendary' }
]

// Filter collection by rarity
const filteredCollection = computed(() => {
    //TODO: figure out if having props.collection.species "isEmpty" check is necessary

    // Return empty array if collection or species is not loaded yet
    if (!props.collection?.species) {
        return []
    }
    if (selectedRarity.value === 'all') {
        return props.collection.species
    }
    return props.collection.species.filter(species =>
        species.rarity.level === selectedRarity.value
    )
})

// Group species by rarity
const collectionByRarity = computed(() => {
    const grouped = {
        legendary: [],
        epic: [],
        rare: [],
        uncommon: [],
        common: []
    }

    props.collection.species.forEach(species => {
        const rarity = species.rarity.level
        if (grouped[rarity]) {
            grouped[rarity].push(species)
        }
    })

    return grouped
})

// Get rarity icon
const getRarityIcon = (rarity: string) => {
    switch (rarity) {
        case 'legendary': return Crown
        case 'epic': return Gem
        case 'rare': return Sparkles
        case 'uncommon': return Star
        case 'common': return BookOpen
        default: return Star
    }
}

// Get rarity stats
const rarityStats = computed(() => {
    const stats = {
        legendary: 0,
        epic: 0,
        rare: 0,
        uncommon: 0,
        common: 0
    }

    // Check if collection and species exist before processing
    if (props.collection?.species) {
        props.collection.species.forEach(species => {
            const rarity = species.rarity.level
            if (stats[rarity] !== undefined) {
                stats[rarity] += species.count
            }
        })
    }

    return stats
})
</script>

<template>
    <CatchEmAllLayout title="Collection" subtitle="Your fursuiter collection" :flash="flash">
        <!-- Collection Stats -->
        <Card class="bg-white shadow-sm">
            <template #content>
                <div class="text-center mb-4">
                    <div class="w-16 h-16 mx-auto mb-3 bg-gradient-to-br from-green-500 to-teal-600 rounded-full flex items-center justify-center">
                        <BookOpen class="w-8 h-8 text-white" />
                    </div>
                    <h2 class="text-xl font-bold text-gray-800">Your Collection</h2>
                    <p class="text-sm text-gray-600" v-if="collection?.totalSpecies !== undefined">
                        {{ collection.totalSpecies }} unique species • {{ collection.totalCatches }} total catches
                    </p>
                    <p class="text-sm text-gray-600" v-else>
                        Loading collection...
                    </p>
                </div>

                <!-- Rarity Distribution -->
                <div class="grid grid-cols-5 gap-2 mb-4">
                    <div class="icon-box text-center bg-yellow-50 rounded-lg border border-yellow-200 rarity-tile">
                        <Crown class="w-5 h-5 mx-auto mb-1 text-yellow-600" />
                        <div class="text-sm font-bold text-yellow-600">{{ rarityStats.legendary }}</div>
                        <div class="icon-text text-yellow-700">Legendary</div>
                    </div>
                    <div class="icon-box text-center bg-purple-50 rounded-lg border border-purple-200 rarity-tile">
                        <Gem class="w-5 h-5 mx-auto mb-1 text-purple-600" />
                        <div class="text-sm font-bold text-purple-600">{{ rarityStats.epic }}</div>
                        <div class="icon-text text-purple-700">Epic</div>
                    </div>
                    <div class="icon-box text-center bg-blue-50 rounded-lg border border-blue-200 rarity-tile">
                        <Sparkles class="w-5 h-5 mx-auto mb-1 text-blue-600" />
                        <div class="text-sm font-bold text-blue-600">{{ rarityStats.rare }}</div>
                        <div class="icon-text text-blue-700">Rare</div>
                    </div>
                    <div class="icon-box text-center bg-green-50 rounded-lg border border-green-200 rarity-tile">
                        <Star class="w-5 h-5 mx-auto mb-1 text-green-600" />
                        <div class="text-sm font-bold text-green-600">{{ rarityStats.uncommon }}</div>
                        <div class="icon-text text-green-700">Uncommon</div>
                    </div>
                    <div class="icon-box text-center bg-gray-50 rounded-lg border border-gray-200 rarity-tile">
                        <BookOpen class="w-5 h-5 mx-auto mb-1 text-gray-600" />
                        <div class="text-sm font-bold text-gray-600">{{ rarityStats.common }}</div>
                        <div class="icon-text text-gray-700">Common</div>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Filters and Controls -->
        <Card class="bg-white shadow-sm">
            <template #content>
                <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between">
                    <!-- Event Filter -->
                    <div v-if="eventOptions.length > 1" class="flex-1 min-w-0">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Event:</label>
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
                    <div class="flex-1 min-w-0">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Rarity:</label>
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
                        <label class="block text-sm font-medium text-gray-700 mb-2">View:</label>
                        <div class="flex rounded-lg border border-gray-300 overflow-hidden">
                            <button @click="viewMode = 'list'"
                                    class="px-3 py-2 transition-colors"
                                    :class="viewMode === 'list' ? 'bg-blue-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'">
                                <List class="w-5 h-5" />
                            </button>
                            <button @click="viewMode = 'grid'"
                                    class="px-3 py-2 transition-colors"
                                    :class="viewMode === 'grid' ? 'bg-blue-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'">
                                <Grid3X3 class="w-5 h-5" />
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Collection Display -->
        <Card class="bg-white shadow-sm">
            <template #content>
                <!-- List View -->
                <div v-if="viewMode === 'list'" class="space-y-3">
                    <div v-for="species in filteredCollection" :key="species.species"
                         class="flex items-center justify-between p-4 rounded-lg border-2 transition-all hover:shadow-md"
                         :class="species.rarity.color.replace('text-', 'border-') + '-200 ' + species.rarity.color.replace('text-', 'bg-') + '-50'">
                        <div class="flex items-center space-x-4">
                            <!-- Rarity Icon -->
                            <div class="w-12 h-12 rounded-full flex items-center justify-center border-2"
                                 :class="species.rarity.color.replace('text-', 'border-') + '-300 ' + species.rarity.color.replace('text-', 'bg-') + '-100'">
                                <component :is="getRarityIcon(species.rarity.level)" class="w-6 h-6" :class="species.rarity.color" />
                            </div>

                            <!-- Species Info -->
                            <div>
                                <div class="font-semibold text-gray-900">{{ species.species }}</div>
                                <div class="text-sm font-medium" :class="species.rarity.color">
                                    {{ species.rarity.label }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ species.count }} {{ species.count === 1 ? 'catch' : 'catches' }}
                                </div>
                            </div>
                        </div>

                        <!-- Points and Count -->
                        <div class="text-right">
                            <div class="text-xl font-bold text-gray-800">×{{ species.count }}</div>
                            <div class="text-sm text-gray-600">{{ species.totalPoints }} pts</div>
                            <div class="text-xs text-gray-500">{{ species.avgPoints }} avg</div>
                        </div>
                    </div>
                </div>

                <!-- Grid View -->
                <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                    <div v-for="species in filteredCollection" :key="species.species"
                         class="p-4 rounded-lg border-2 text-center transition-all hover:shadow-md cursor-pointer"
                         :class="species.rarity.color.replace('text-', 'border-') + '-200 ' + species.rarity.color.replace('text-', 'bg-') + '-50 hover:scale-105'">
                        <!-- Rarity Icon -->
                        <div class="w-12 h-12 mx-auto mb-3 rounded-full flex items-center justify-center border-2"
                             :class="species.rarity.color.replace('text-', 'border-') + '-300 ' + species.rarity.color.replace('text-', 'bg-') + '-100'">
                            <component :is="getRarityIcon(species.rarity.level)" class="w-6 h-6" :class="species.rarity.color" />
                        </div>

                        <!-- Species Info -->
                        <div class="font-semibold text-gray-900 text-sm mb-1">{{ species.species }}</div>
                        <div class="text-xs font-medium mb-2" :class="species.rarity.color">
                            {{ species.rarity.label }}
                        </div>
                        <div class="text-lg font-bold text-gray-800">×{{ species.count }}</div>
                        <div class="text-xs text-gray-500">{{ species.totalPoints }} pts</div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-if="filteredCollection.length === 0" class="text-center py-12">
                    <Filter v-if="selectedRarity !== 'all'" class="w-16 h-16 mx-auto mb-4 text-gray-300" />
                    <BookOpen v-else class="w-16 h-16 mx-auto mb-4 text-gray-300" />
                    <h3 class="text-lg font-medium text-gray-600 mb-2">
                        {{ selectedRarity !== 'all' ? 'No species found' : 'No collection yet' }}
                    </h3>
                    <p class="text-gray-500">
                        {{ selectedRarity !== 'all' ? 'Try a different rarity filter or start catching more fursuiters!' : 'Start catching fursuiters to build your collection!' }}
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

/* Grid view animations */
@keyframes sparkle {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; transform: scale(1.1); }
}

.legendary-sparkle {
    animation: sparkle 2s ease-in-out infinite;
}
</style>
