<script setup>
import {computed, reactive, ref} from "vue";
import {Cropper} from 'vue-advanced-cropper'
import 'vue-advanced-cropper/dist/style.css';
import {Check, Plus, TriangleAlert, X, ZoomIn, ZoomOut} from '@lucide/vue';
import {Button} from '@/Components/UI/button';
import {Alert, AlertDescription} from '@/Components/UI/alert';

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
const fileInput = ref(null);

// Smallest crop the badge can be printed from, mirrored from FursuitImageService.
const MIN_CROP_WIDTH = 240;
const MIN_CROP_HEIGHT = 320;

const emits = defineEmits(['updateImage', 'updateSource'])

const onSelectFile = (event) => {
    const {files} = event.target;
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
            <Button variant="secondary" size="icon" aria-label="Zoom out" @click="zoom(0.8)"><ZoomOut/></Button>
            <span class="text-xs text-muted-foreground">Drag the photo to position it, pinch or use the buttons to zoom</span>
            <Button variant="secondary" size="icon" aria-label="Zoom in" @click="zoom(1.25)"><ZoomIn/></Button>
        </div>

        <Alert v-if="cropTooSmall" variant="warning" class="mb-3">
            <TriangleAlert/>
            <AlertDescription>
                You have zoomed in too far. The selected area must be at least {{ MIN_CROP_WIDTH }}x{{ MIN_CROP_HEIGHT }}
                pixels of the original photo, otherwise it will print blurry.
            </AlertDescription>
        </Alert>
    </template>

    <!-- Rules -->
    <div class="text-sm mt-2 my-4 space-y-2">
        <p class="max-w-xs">All photos will be manually reviewed before printing. We ask that your photo follows the <a href="https://help.eurofurence.org/legal/roc/" target="_blank">code of conduct</a>.</p>
        <p v-if="hasTransparency" class="max-w-xs">Transparent areas will be filled with a solid white background when the badge is printed.</p>
    </div>

    <div :class="{'grid grid-cols-2 gap-3': image.src}">
        <div v-if="image.src" class="w-full">
            <Button class="w-full bg-success text-white hover:bg-success/90" :disabled="cropTooSmall"
                    @click="confirmImage()"><Check/> Confirm</Button>
        </div>

        <template v-if="!image.src">
            <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onSelectFile">
            <Button class="w-full" @click="fileInput.click()"><Plus/> Choose Image</Button>
        </template>

        <div v-if="image.src" class="w-full">
            <Button variant="destructive" class="w-full" @click="image.src = null"><X/> Cancel</Button>
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
