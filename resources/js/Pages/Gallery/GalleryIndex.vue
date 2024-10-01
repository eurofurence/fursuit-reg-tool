<script setup lang="ts">
// noinspection TypeScriptCheckImport
import Layout from "@/Layouts/Layout.vue";
import {Head, router} from '@inertiajs/vue3'
// noinspection TypeScriptCheckImport
import GalleryItem from "@/Components/GalleryItem.vue";
import {ref} from "vue";

defineOptions({layout: Layout})

const props = defineProps({
    fursuit: Array,
    site: Number,
    maxSite: Number,
})

const imageViewIsOpen = ref(false)
const viewFursuit = ref(null)

function toggleImageView() {
    imageViewIsOpen.value = !imageViewIsOpen.value
}

function setImageView(fursuit: Object) {
    viewFursuit.value = fursuit
    const imageView = document.getElementById('ImageViewImage')
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
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 justify-center">
        <GalleryItem
            v-for="fursuit in props.fursuit"
            :key="fursuit.id"
            :fursuit="fursuit"
            @click="() => {toggleImageView(); setImageView(fursuit);}"
        />
    </div>
    <div id="ImageViewOverlay" @click="toggleImageView"
         class="w-screen h-screen bg-black/50 fixed top-0 left-0 transition-all flex justify-center items-center"
         :class="{'opacity-100': imageViewIsOpen, 'opacity-0 pointer-events-none': !imageViewIsOpen}">
        <div id="ImageView">
            <img id="ImageViewImage" src="" alt="Fursuit" class="select-none" @click="openImageInNewTab"/>
            <div id="ImageMeta" class="bg-black/50 rounded-lg mt-2 p-2">
                <p class="text-white text-2xl"><strong class="text-white-300">Name: </strong>{{ viewFursuit?.name }}</p>
                <p class="text-white text-lg"><strong  class="text-white-300">Species: </strong>{{ viewFursuit?.species }}</p>
                <p class="text-white text-lg"><strong  class="text-white-300">Caught: </strong>{{ viewFursuit?.scoring }}</p>
            </div>
        </div>
    </div>
    <div id="pagination" class="flex w-full justify-center pt-10">
        <div class="flex items-center gap-8">
            <button :disabled="props.site === 1"
                    class="rounded-md border border-slate-300 p-2.5 text-center text-sm transition-all shadow-sm hover:shadow-lg text-slate-600 hover:text-white hover:bg-slate-800 hover:border-slate-800 focus:text-white focus:bg-slate-800 focus:border-slate-800 active:border-slate-800 active:text-white active:bg-slate-800 disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                    type="button"
                    @click="() => {router.visit(route('gallery.site', {site: props.site - 1}))}">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                    <path fill-rule="evenodd"
                          d="M11.03 3.97a.75.75 0 0 1 0 1.06l-6.22 6.22H21a.75.75 0 0 1 0 1.5H4.81l6.22 6.22a.75.75 0 1 1-1.06 1.06l-7.5-7.5a.75.75 0 0 1 0-1.06l7.5-7.5a.75.75 0 0 1 1.06 0Z"
                          clip-rule="evenodd"/>
                </svg>
            </button>

            <p class="text-slate-600">
                Page <strong class="text-slate-800">{{ props.site }}</strong> of&nbsp;<strong class="text-slate-800">{{
                    props.maxSite
                }}</strong>
            </p>

            <button :disabled="props.site === props.maxSite"
                    class="rounded-md border border-slate-300 p-2.5 text-center text-sm transition-all shadow-sm hover:shadow-lg text-slate-600 hover:text-white hover:bg-slate-800 hover:border-slate-800 focus:text-white focus:bg-slate-800 focus:border-slate-800 active:border-slate-800 active:text-white active:bg-slate-800 disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                    type="button"
                    @click="() => {router.visit(route('gallery.site', {site: props.site + 1}))}">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                    <path fill-rule="evenodd"
                          d="M12.97 3.97a.75.75 0 0 1 1.06 0l7.5 7.5a.75.75 0 0 1 0 1.06l-7.5 7.5a.75.75 0 1 1-1.06-1.06l6.22-6.22H3a.75.75 0 0 1 0-1.5h16.19l-6.22-6.22a.75.75 0 0 1 0-1.06Z"
                          clip-rule="evenodd"/>
                </svg>
            </button>
        </div>
    </div>
</template>

<style scoped>
#ImageViewImage {
    width: 600px;
    height: auto;
    max-width: 100vw;
}
</style>
