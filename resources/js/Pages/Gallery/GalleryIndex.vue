<script setup lang="ts">
import Layout from "@/Layouts/Layout.vue";
import GalleryItem from "@/Components/Gallery/GalleryItem.vue";
import RankingBanner from "@/Components/Gallery/RankingBanner.vue";
import { Head, router } from '@inertiajs/vue3'
import { ref, watch, computed, onMounted, onUnmounted, nextTick } from "vue";

interface Fursuit {
    id: number,
    name: string,
    species: string,
    image: string,
    scoring: number,
    event?: string,
    archival_notice?: string,
}

interface Ranking {
    user: string,
    rank: number,
    catches: number,
}

interface Filters {
    search: string,
    species: string,
    event: string,
    sort: string,
}

interface SpeciesOption {
    value: string,
    label: string,
}

interface EventOption {
    value: string | number,
    label: string,
}

defineOptions({layout: Layout})

interface SelectedEvent {
    id: number,
    name: string,
    archival_notice?: string,
    catch_em_all_enabled: boolean,
}

const props = defineProps<{
    fursuits: Fursuit[],
    ranking: Ranking[],
    has_more: boolean,
    total: number,
    filters: Filters,
    species_options: SpeciesOption[],
    event_options: EventOption[],
    is_historical_event?: boolean,
    selected_event?: SelectedEvent,
}>()

// Reactive state
const allFursuits = ref<Fursuit[]>([...props.fursuits]);
const searchQuery = ref<string>(props.filters.search || '');
const selectedSpecies = ref<string>(props.filters.species || '');
const selectedEvent = ref<string>(props.filters.event || '');
const selectedSort = ref<string>(props.filters.sort || (props.is_historical_event ? 'name_asc' : 'catches_desc'));
const isLoading = ref<boolean>(false);
const hasMore = ref<boolean>(props.has_more);
const imageViewIsOpen = ref<boolean>(false);
const viewFursuit = ref<Fursuit | null>(null);
const loadingTrigger = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

// Computed properties
const sortOptions = computed(() => {
    const options = [
        { value: 'name_asc', label: 'Name A-Z' },
        { value: 'name_desc', label: 'Name Z-A' },
    ];

    // Only show catch-related sorting for non-historical events
    if (!props.is_historical_event) {
        options.unshift(
            { value: 'catches_desc', label: 'Most Caught' },
            { value: 'catches_asc', label: 'Least Caught' }
        );
    }

    return options;
});

const hasResults = computed(() => allFursuits.value && allFursuits.value.length > 0);

// Methods
function applyFilters() {
    // Reset fursuits and load from beginning
    allFursuits.value = [];
    hasMore.value = true;

    router.get(route('gallery.index'), {
        query: searchQuery.value,
        species: selectedSpecies.value,
        event: selectedEvent.value,
        sort: selectedSort.value,
        offset: 0,
    }, {
        replace: true,
        preserveScroll: false,
        preserveState: false,
        onSuccess: (page) => {
            // Update fursuits from the response
            allFursuits.value = page.props.fursuits as Fursuit[];
            hasMore.value = page.props.has_more as boolean;

            // Re-setup intersection observer after DOM update
            nextTick(() => {
                setupIntersectionObserver();
            });
        }
    });
}

function resetFilters() {
    searchQuery.value = '';
    selectedSpecies.value = '';
    selectedEvent.value = '';
    selectedSort.value = props.is_historical_event ? 'name_asc' : 'catches_desc';
    applyFilters();
}

async function loadMoreFursuits() {
    if (isLoading.value || !hasMore.value) return;

    isLoading.value = true;

    try {
        const params = new URLSearchParams({
            query: searchQuery.value || '',
            species: selectedSpecies.value || '',
            event: selectedEvent.value || '',
            sort: selectedSort.value || 'catches_desc',
            offset: allFursuits.value.length.toString(),
        });

        const response = await fetch(`${route('gallery.load-more')}?${params}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) throw new Error('Failed to load more fursuits');

        const data = await response.json();

        // Append new fursuits to existing ones
        allFursuits.value.push(...data.fursuits);
        hasMore.value = data.has_more;

    } catch (error) {
        console.error('Error loading more fursuits:', error);
    } finally {
        isLoading.value = false;
    }
}

function setupIntersectionObserver() {
    if (observer) {
        observer.disconnect();
    }

    if (!loadingTrigger.value) return;

    observer = new IntersectionObserver(
        (entries) => {
            const entry = entries[0];
            if (entry.isIntersecting && hasMore.value && !isLoading.value) {
                loadMoreFursuits();
            }
        },
        {
            root: null,
            rootMargin: '100px',
            threshold: 0.1
        }
    );

    observer.observe(loadingTrigger.value);
}

function toggleImageView() {
    imageViewIsOpen.value = !imageViewIsOpen.value;
    if (!imageViewIsOpen.value) {
        viewFursuit.value = null;
    }
}

function setImageView(fursuit: Fursuit) {
    viewFursuit.value = fursuit;
    imageViewIsOpen.value = true;
}

function openImageInNewTab() {
    if (viewFursuit.value) {
        window.open(viewFursuit.value.image, '_blank');
    }
}

// Debounced search watcher
let timeoutId: NodeJS.Timeout | null = null;
watch([searchQuery, selectedSpecies, selectedEvent, selectedSort], () => {
    if (timeoutId) clearTimeout(timeoutId);
    timeoutId = setTimeout(() => {
        applyFilters();
    }, 500);
}, { deep: true });

// Lifecycle hooks
onMounted(() => {
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && imageViewIsOpen.value) {
            toggleImageView();
        }
    });

    nextTick(() => {
        setupIntersectionObserver();
    });
});

onUnmounted(() => {
    if (observer) {
        observer.disconnect();
    }
    if (timeoutId) {
        clearTimeout(timeoutId);
    }
});
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <Head title="Fursuit Gallery" />

        <!-- Enhanced Ranking Banner -->
        <div v-if="!is_historical_event" class="bg-gradient-to-r from-blue-600 to-purple-600 text-white">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <RankingBanner :ranking="ranking" />
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Header Section -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">Fursuit Gallery</h1>
                <p class="text-gray-600">
                    Discover amazing fursuits from Eurofurence - {{ total }} total attendees
                </p>
            </div>

            <!-- Archival Notice -->
            <div v-if="selected_event?.archival_notice" class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-8">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-amber-600 mt-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <div class="text-sm text-amber-800">
                            {{ selected_event.archival_notice }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8">
                <div class="flex flex-col lg:flex-row gap-4">
                    <!-- Search Bar -->
                    <div class="flex-1">
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-2">
                            Search Fursuits
                        </label>
                        <input
                            id="search"
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search by name..."
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                        />
                    </div>

                    <!-- Event Filter -->
                    <div class="lg:w-64">
                        <label for="event" class="block text-sm font-medium text-gray-700 mb-2">
                            Event
                        </label>
                        <select
                            id="event"
                            v-model="selectedEvent"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                        >
                            <option value="">All Events</option>
                            <option v-for="event in event_options" :key="event.value" :value="event.value">
                                {{ event.label }}
                            </option>
                        </select>
                    </div>

                    <!-- Species Filter -->
                    <div class="lg:w-64">
                        <label for="species" class="block text-sm font-medium text-gray-700 mb-2">
                            Species
                        </label>
                        <select
                            id="species"
                            v-model="selectedSpecies"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                        >
                            <option value="">All Species</option>
                            <option v-for="species in species_options" :key="species.value" :value="species.value">
                                {{ species.label }}
                            </option>
                        </select>
                    </div>

                    <!-- Sort Options -->
                    <div class="lg:w-48">
                        <label for="sort" class="block text-sm font-medium text-gray-700 mb-2">
                            Sort By
                        </label>
                        <select
                            id="sort"
                            v-model="selectedSort"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                        >
                            <option v-for="option in sortOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Filter Actions -->
                <div class="flex justify-between items-center mt-4 pt-4 border-t border-gray-200">
                    <div class="text-sm text-gray-600">
                        Showing {{ allFursuits.length }} of {{ total }} fursuits
                        <span v-if="hasMore" class="text-blue-600">(scroll for more)</span>
                    </div>
                    <button
                        @click="resetFilters"
                        class="text-blue-600 hover:text-blue-800 text-sm font-medium transition-colors"
                    >
                        Clear Filters
                    </button>
                </div>
            </div>

            <!-- Gallery Grid -->
            <div v-if="!hasResults && !isLoading" class="text-center py-16">
                <div class="text-gray-400 mb-4">
                    <svg class="mx-auto h-16 w-16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No fursuits found</h3>
                <p class="text-gray-600">Try adjusting your search or filter criteria</p>
            </div>

            <!-- Fursuits Grid -->
            <div v-if="hasResults" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 mb-8">
                <div
                    v-for="fursuit in allFursuits"
                    :key="fursuit.id"
                    @click="setImageView(fursuit)"
                    class="cursor-pointer transform transition-transform hover:scale-105"
                >
                    <GalleryItem :fursuit="fursuit" />
                    <div class="mt-3 text-center">
                        <div v-if="fursuit.scoring > 0" class="flex items-center justify-center gap-2 text-sm text-gray-600">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                            </svg>
                            {{ fursuit.scoring }} catches
                        </div>
                        <div v-if="fursuit.event" class="text-xs text-gray-500 mt-1">
                            {{ fursuit.event }}
                        </div>
                        <div v-if="selected_event?.archival_notice" class="text-xs text-amber-600 mt-1 font-medium">
                            📜 Archival
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loading Trigger and Indicator -->
            <div ref="loadingTrigger" class="flex justify-center py-8">
                <div v-if="isLoading" class="flex items-center space-x-2 text-gray-600">
                    <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>Loading more fursuits...</span>
                </div>
                <div v-else-if="!hasMore && hasResults" class="text-gray-500 text-center">
                    <p>🎉 You've reached the end!</p>
                    <p class="text-sm mt-1">That's all {{ allFursuits.length }} fursuits</p>
                </div>
            </div>
        </div>

        <!-- Image View Modal -->
        <div
            v-if="imageViewIsOpen"
            @click="toggleImageView"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-75 backdrop-blur-sm transition-all"
        >
            <div @click.stop class="relative max-w-4xl max-h-[90vh] mx-4">
                <button
                    @click="toggleImageView"
                    class="absolute -top-12 right-0 text-white hover:text-gray-300 transition-colors"
                >
                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <img
                    v-if="viewFursuit"
                    :src="viewFursuit.image"
                    :alt="viewFursuit.name"
                    @click="openImageInNewTab"
                    class="max-w-full max-h-[80vh] object-contain rounded-lg cursor-pointer"
                />

                <div v-if="viewFursuit" class="bg-black bg-opacity-50 rounded-lg mt-4 p-4 text-white">
                    <h3 class="text-2xl font-bold mb-2">{{ viewFursuit.name }}</h3>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                            </svg>
                            <span>{{ viewFursuit.species }}</span>
                        </div>
                        <div v-if="viewFursuit.scoring > 0" class="flex items-center gap-2">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                            </svg>
                            <span>{{ viewFursuit.scoring }} catches</span>
                        </div>
                        <div v-if="viewFursuit.event" class="flex items-center gap-2">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <span>{{ viewFursuit.event }}</span>
                        </div>
                        <div v-if="selected_event?.archival_notice" class="flex items-center gap-2 text-amber-200">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="text-sm">📜 {{ selected_event.archival_notice }}</span>
                        </div>
                    </div>
                    <p class="text-xs text-gray-300 mt-2">Click image to open in new tab • Press ESC to close</p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Custom scrollbar for webkit browsers */
::-webkit-scrollbar {
    width: 8px;
}

::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Smooth appear animation for new items */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.fade-in-up {
    animation: fadeInUp 0.3s ease-out;
}
</style>
