<script setup lang="ts">
// @ts-ignore
import Layout from "@/Layouts/Layout.vue";
// @ts-ignore
import GalleryItem from "@/Components/Gallery/GalleryItem.vue";
// @ts-ignore
import Pagination from "@/Components/Gallery/Pagination.vue";
import {Head, router} from '@inertiajs/vue3'
import {ref} from "vue";

/**
 * API Item for the gallery used by the backend
 *
 * Fursuit interface for usage for the gallery items
 */
interface Fursuit {
    /**
     * Database ID of the fursuit
     */
    id: number,
    /**
     * Name of the fursuit
     */
    name: string,
    /**
     * Species of the fursuit
     */
    species: string,
    /**
     * Image url of the fursuit (Temporary link)
     */
    image: string,
    /**
     * Fursuit got caught X times
     */
    scoring: number,
}


defineOptions({layout: Layout})

const props = defineProps({
    fursuit: Array<Fursuit>,
    ranking: Array<String>,
    site: Number,
    maxSite: Number,
    suiteAmount: Number,
    search: String,
})

const totalFursuits = ref<number>(0)

async function getFursuitAmount() {
    sessionStorage.getItem('fursuitAmount') ? totalFursuits.value = parseInt(sessionStorage.getItem('fursuitAmount')) : await fetchFursuitAmount()
}

async function fetchFursuitAmount() {
    const response = await fetch(route('gallery.count'), {
        method: 'get',
    })
    const data = await response.json()
    totalFursuits.value = data.count
    sessionStorage.setItem('fursuitAmount', data.count)
}

// TODO: Remove
console.log(props.ranking)

const imageViewIsOpen = ref<boolean>(false)
const viewFursuit = ref<Fursuit>(null)

function toggleImageView() {
    imageViewIsOpen.value = !imageViewIsOpen.value
}

function setImageView(fursuit: Fursuit) {
    viewFursuit.value = fursuit
    const imageView = document.getElementById('ImageViewImage') as HTMLImageElement
    if (imageView) {
        imageView.src = fursuit.image
    }
}

function openImageInNewTab() {
    if (viewFursuit.value) {
        window.open(viewFursuit.value.image, '_blank')
    }
}

function search() {
    const search = document.getElementById("searchbar") as HTMLInputElement
    router.visit(route('gallery.site', {site: 1}), {
        method: 'get',
        data: {
            s: search.value || "",
        }
    })
}

function goToPage(page: number) {
    router.visit(route('gallery.site', {site: page}), {
        method: 'get',
        data: {
            s: props.search,
        }
    })
}

getFursuitAmount()
</script>

<template>
    <Head title="Gallery"/>
    <div class="py-16 flex justify-between items-center flex-wrap">
        <div class="md:w-1/4 w-screen text-center md:text-lef md:border md:rounded-lg py-2">
            <p class="text-lg font-semibold">Total Fursuits: {{ totalFursuits }} ({{ props.suiteAmount}})</p>
        </div>
        <form @submit="e => {
                e.preventDefault();
                search()
            }"
              class="flex items-center space-x-4 md:w-7/12 w-screen md:justify-end justify-center">
            <input id="searchbar" type="text" placeholder="Search..." class="border rounded-lg p-2 w-3/4"
                   :value="props.search"
            />
            <button @click="toggleMenu" class="bg-blue-500 text-white p-2 rounded-lg" type="button">Sorting</button>
        </form>
    </div>
    <div class="text-center text-4xl font-bold w-full h-full"
         v-if="props.fursuit === null || props.fursuit.length === 0">No results found :/
    </div>
    <!-- Gallery -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 justify-center">
        <GalleryItem
            v-for="fursuit in props.fursuit"
            :key="fursuit.id"
            :fursuit="fursuit"
            @click="() => {toggleImageView(); setImageView(fursuit);}"
        />
    </div>
    <!-- Image View Overlay -->
    <div id="ImageViewOverlay" @click="toggleImageView"
         class="w-screen h-screen bg-black/50 fixed top-0 left-0 transition-all flex justify-center items-center z-50"
         :class="{
            'opacity-100': imageViewIsOpen,
            'opacity-0 pointer-events-none': !imageViewIsOpen,
        }">
        <div id="ImageView">
            <img id="ImageViewImage" src="" alt="Fursuit" class="select-none" @click="openImageInNewTab"/>
            <div id="ImageMeta" class="bg-black/50 rounded-lg mt-2 p-2">
                <p class="text-white text-2xl">
                    <strong class="text-white-300">Name: </strong>{{ viewFursuit?.name }}
                </p>
                <p class="text-white text-lg">
                    <strong class="text-white-300">Species: </strong>{{ viewFursuit?.species }}
                </p>
                <p class="text-white text-lg">
                    <strong class="text-white-300">Caught: </strong>{{ viewFursuit?.scoring }}
                </p>
            </div>
        </div>
    </div>
    <!-- Pagnation -->
    <Pagination
        :site="props.site"
        :maxSite="props.maxSite"
        :routeForwards="() => {goToPage(props.site + 1);}"
        :routeBackwards="() => {goToPage(props.site - 1);}"
        v-if="!(props.fursuit === null || props.fursuit.length === 0)"
    />
</template>

<style scoped>
#ImageViewImage {
    width: 600px;
    height: auto;
    max-width: 100vw;
}
</style>
