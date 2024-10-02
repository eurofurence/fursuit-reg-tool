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
    site: Number,
    maxSite: Number,
})

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
</script>

<template>
    <Head title="Gallery"/>
    <div class="py-16">
        <div class="text-xl">Gallery</div>
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
        :routeForwards="() => {router.visit(route('gallery.site', { site: props.site + 1 }));}"
        :routeBackwards="() => {router.visit(route('gallery.site', { site: props.site - 1 }));}"
    />
</template>

<style scoped>
#ImageViewImage {
    width: 600px;
    height: auto;
    max-width: 100vw;
}
</style>
