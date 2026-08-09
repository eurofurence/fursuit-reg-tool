<script setup>
import FileUpload from 'primevue/fileupload';
import {computed, reactive, ref} from "vue";
import {Cropper} from 'vue-advanced-cropper'
import 'vue-advanced-cropper/dist/style.css';
import Button from '@/Components/UI/UiButton.vue';
import InlineMessage from "@/Components/UI/UiMessage.vue";

const props = defineProps({
    imageSource: {
        type: Object,
        default: {
            src: null,
            type: null,
        }
    }
});

const image = reactive(props.imageSource);

// The original file the attendee picked. It is uploaded untouched; the crop
// rectangle travels alongside it and the server does the cutting. Nothing is
// drawn into a canvas here - that is what used to break on iOS and on older
// browsers with EXIF-rotated photos.
const file = ref(null);
const coordinates = ref(null);
const naturalSize = ref(null);
const cropper = ref(null);

// Smallest crop the badge can be printed from, mirrored from FursuitImageService.
const MIN_CROP_WIDTH = 240;
const MIN_CROP_HEIGHT = 320;

const emits = defineEmits(['updateImage', 'updateSource'])

const onSelectFile = (event) => {
    const {files} = event;
    if (files && files[0]) {
        // Revoke the previous object URL so the garbage collector can drop it.
        if (image.src) {
            URL.revokeObjectURL(image.src)
        }
        file.value = files[0];
        image.src = URL.createObjectURL(files[0]);
        image.type = files[0].type;
        image.name = files[0].name;
        coordinates.value = null;
        emits('updateSource', {
            src: image.src,
            type: image.type,
            name: image.name,
        });
    }
}

// The stencil is fixed in the middle and the image is dragged and zoomed behind
// it (the way Telegram crops an avatar). Sizing it from the boundaries keeps it
// filling the dialog on phones as well as desktop.
const stencilSize = ({boundaries}) => {
    const width = Math.min(boundaries.width * 0.9, boundaries.height * 0.9 * 3 / 4);

    return {width, height: width * 4 / 3};
};

const onChangeCrop = (event) => {
    coordinates.value = event.coordinates;
    naturalSize.value = {width: event.image.width, height: event.image.height};
}

// Zoomed in too far: the selected area is fewer pixels than the badge needs.
const cropTooSmall = computed(() => {
    if (!coordinates.value) {
        return false;
    }

    return coordinates.value.width < MIN_CROP_WIDTH || coordinates.value.height < MIN_CROP_HEIGHT;
});

const hasTransparency = computed(() => image.type === 'image/png');

function zoom(factor) {
    cropper.value?.zoom(factor);
}

function confirmImage() {
    if (!coordinates.value || cropTooSmall.value) {
        return;
    }
    emits('updateImage', {
        file: file.value,
        src: image.src,
        type: image.type,
        naturalSize: naturalSize.value,
        crop: {
            x: Math.round(coordinates.value.left),
            y: Math.round(coordinates.value.top),
            width: Math.round(coordinates.value.width),
            height: Math.round(coordinates.value.height),
        },
    });
    emits('updateSource', image);
}

</script>
<template>

    <template v-if="image.src">
        <Cropper
            ref="cropper"
            :src="image.src"
            :type="image.type"
            class="badge-cropper mb-2"
            :canvas="false"
            :stencil-size="stencilSize"
            :stencil-props="{
                handlers: {},
                movable: false,
                resizable: false,
                aspectRatio: 3/4,
            }"
            image-restriction="stencil"
            default-boundaries="fill"
            @change="onChangeCrop"
        />

        <div class="flex items-center justify-center gap-2 mb-3">
            <Button severity="secondary" icon="pi pi-search-minus" aria-label="Zoom out" @click="zoom(0.8)"/>
            <span class="text-xs text-gray-500">Drag the photo to position it, pinch or use the buttons to zoom</span>
            <Button severity="secondary" icon="pi pi-search-plus" aria-label="Zoom in" @click="zoom(1.25)"/>
        </div>

        <InlineMessage v-if="cropTooSmall" severity="warn" class="mb-3">
            You have zoomed in too far. The selected area must be at least {{ MIN_CROP_WIDTH }}x{{ MIN_CROP_HEIGHT }}
            pixels of the original photo, otherwise it will print blurry.
        </InlineMessage>
    </template>

    <!-- Rules -->
    <div class="text-sm mt-2 my-4 space-y-2">
        <p class="max-w-xs">All photos will be manually reviewed before printing. We ask that your photo follows the <a href="https://help.eurofurence.org/legal/roc/" target="_blank">code of conduct</a>.</p>
        <p v-if="hasTransparency" class="max-w-xs">Transparent areas will be filled with a solid white background when the badge is printed.</p>
    </div>

    <div :class="{'grid grid-cols-2 gap-3': image.src}">
        <div v-if="image.src" class="w-full">
            <Button severity="success" label="Confirm" icon="pi pi-check" class="w-full" :disabled="cropTooSmall"
                    @click="confirmImage()"/>
        </div>

        <FileUpload mode="basic" accept="image/*" :auto="false" class="w-full" :maxFileSize="8000000"
                    @select="onSelectFile"
                    v-if="!image.src"
                    choose-label="Choose Image">
        </FileUpload>

        <div v-if="image.src" class="w-full">
            <Button severity="danger" label="Cancel" icon="pi pi-times" class="w-full" @click="image.src = null"/>
        </div>
    </div>
</template>

<style scoped>
/* The cropper has no intrinsic height; without one the fixed stencil has no
   boundaries to size itself against. */
.badge-cropper {
    height: 22rem;
    background: #1f2937;
}
</style>
