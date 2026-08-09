<script setup lang="ts">
import Layout from "@/Layouts/Layout.vue";
import RankingBanner from "@/Components/Gallery/RankingBanner.vue";
import { Head, Link } from '@inertiajs/vue3'
import { computed, ref } from "vue";

interface Folder {
    id: number,
    name: string,
    year?: string,
    fursuits: number,
    archival_notice?: string,
    catch_em_all_enabled: boolean,
    cover?: string,
}

interface Total {
    fursuits: number,
    cover?: string,
}

interface Ranking {
    user: string,
    rank: number,
    catches: number,
}

defineOptions({layout: Layout})

const props = defineProps<{
    folders: Folder[],
    total: Total,
    ranking: Ranking[],
    ranking_event?: string | null,
}>()

const search = ref<string>('');

const visibleFolders = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) return props.folders;

    return props.folders.filter(folder =>
        folder.name.toLowerCase().includes(term) || (folder.year ?? '').includes(term)
    );
});

function numberFormat(value: number): string {
    return new Intl.NumberFormat().format(value ?? 0);
}
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <Head title="Fursuit Gallery" />

        <div v-if="ranking.length" class="site-container pt-8">
            <RankingBanner :ranking="ranking" :event-name="ranking_event" />
        </div>

        <div class="site-container py-8">
            <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 mb-2">Fursuit Gallery</h1>
                    <p class="text-gray-600">
                        {{ numberFormat(total.fursuits) }} fursuits. Pick a convention to browse.
                    </p>
                </div>

                <div class="lg:w-72">
                    <label for="folder-search" class="block text-sm font-medium text-gray-700 mb-2">
                        Find a convention
                    </label>
                    <input
                        id="folder-search"
                        v-model="search"
                        type="text"
                        placeholder="e.g. EF28 or 2024"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                    />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <!-- Everything, across every convention -->
                <Link
                    :href="route('gallery.all')"
                    class="folder group block focus:outline-none focus:ring-2 focus:ring-blue-500 rounded-b-xl rounded-tr-xl"
                >
                    <div class="folder-tab bg-blue-600"></div>
                    <div class="folder-body border-blue-200 bg-white">
                        <div class="folder-cover bg-blue-50">
                            <img
                                v-if="total.cover"
                                :src="total.cover"
                                alt=""
                                class="w-full h-full object-cover object-center transition-transform duration-300 group-hover:scale-105"
                                loading="lazy"
                            />
                            <div class="absolute inset-0 bg-gradient-to-t from-black/70 to-transparent"></div>
                            <div class="absolute bottom-3 left-4 right-4 text-white">
                                <h2 class="text-xl font-bold drop-shadow">All Fursuits</h2>
                                <p class="text-sm opacity-90">Every convention at once</p>
                            </div>
                        </div>
                        <div class="px-4 py-3 text-sm text-gray-600">
                            {{ numberFormat(total.fursuits) }} fursuits
                        </div>
                    </div>
                </Link>

                <Link
                    v-for="folder in visibleFolders"
                    :key="folder.id"
                    :href="route('gallery.event', folder.id)"
                    class="folder group block focus:outline-none focus:ring-2 focus:ring-blue-500 rounded-b-xl rounded-tr-xl"
                >
                    <div class="folder-tab" :class="folder.archival_notice ? 'bg-amber-500' : 'bg-gray-400'"></div>
                    <div class="folder-body border-gray-200 bg-white">
                        <div class="folder-cover bg-gray-100">
                            <img
                                v-if="folder.cover"
                                :src="folder.cover"
                                :alt="folder.name"
                                class="w-full h-full object-cover object-center transition-transform duration-300 group-hover:scale-105"
                                loading="lazy"
                            />
                            <div v-else class="flex h-full items-center justify-center text-gray-400">
                                <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div class="absolute inset-0 bg-gradient-to-t from-black/70 to-transparent"></div>
                            <div class="absolute bottom-3 left-4 right-4 text-white">
                                <h2 class="text-xl font-bold drop-shadow">{{ folder.name }}</h2>
                                <p v-if="folder.year" class="text-sm opacity-90">{{ folder.year }}</p>
                            </div>
                            <div
                                v-if="folder.archival_notice"
                                class="absolute top-3 right-3 rounded-full bg-amber-500/90 px-2 py-1 text-xs font-semibold text-white shadow"
                            >
                                Archive
                            </div>
                        </div>
                        <div class="px-4 py-3 text-sm text-gray-600">
                            {{ numberFormat(folder.fursuits) }} fursuits
                        </div>
                    </div>
                </Link>
            </div>

            <div v-if="!visibleFolders.length" class="text-center py-16">
                <h3 class="text-lg font-medium text-gray-900 mb-2">No conventions match "{{ search }}"</h3>
                <p class="text-gray-600">Clear the search to see every year.</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* A card that reads as a folder: a small tab on top of a rounded body. */
.folder {
    transition: transform 0.2s ease, filter 0.2s ease;
}

.folder:hover {
    transform: translateY(-4px);
}

.folder-tab {
    width: 45%;
    height: 0.75rem;
    border-radius: 0.5rem 0.5rem 0 0;
}

.folder-body {
    border-width: 1px;
    border-radius: 0 0.75rem 0.75rem 0.75rem;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    transition: box-shadow 0.2s ease;
}

.folder:hover .folder-body {
    box-shadow: 0 12px 20px -8px rgba(0, 0, 0, 0.25);
}

.folder-cover {
    position: relative;
    aspect-ratio: 4 / 3;
    overflow: hidden;
}
</style>
