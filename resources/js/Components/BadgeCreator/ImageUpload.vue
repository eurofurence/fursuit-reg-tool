<script setup>
import FileUpload from 'primevue/fileupload';
import {reactive, ref} from "vue";
import {Cropper} from 'vue-advanced-cropper'
import 'vue-advanced-cropper/dist/style.css';
import pica from 'pica';
import Button from 'primevue/button';
import InlineMessage from "primevue/inlinemessage";

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

const croppedImage = ref(null);
const rawBlob = ref(null);
const picaInstance = pica();

const emits = defineEmits(['updateImage', 'updateSource'])

const onSelectFile = (event) => {
    // Reference to the DOM input element
    const {files} = event;
    // Ensure that you have a file before attempting to read it
    if (files && files[0]) {
        // 1. Revoke the object URL, to allow the garbage collector to destroy the uploaded before file
        if (image.src) {
            URL.revokeObjectURL(image.src)
        }
        // 2. Create the blob link to the file to optimize performance:
        image.src = URL.createObjectURL(files[0]);
        image.type = files[0].type;
        emits('updateSource', {
            src: image.src,
            type: image.type
        });
    }
}

const onChangeCrop = (event) => {
    const {coordinates, canvas} = event;
    // Save coordinates to img
    image.coordinates = {
        left: coordinates.left,
        top: coordinates.top,
    };
    image.size = {
        width: coordinates.width,
        height: coordinates.height,
    };
    // Resize using pica
    picaInstance.toBlob(canvas, image.type)
        .then(blob => {
            if (croppedImage.value) {
                // Free up memory
                URL.revokeObjectURL(croppedImage.value);
            }
            rawBlob.value = blob;
            croppedImage.value = URL.createObjectURL(blob);
        })
        .catch(error => {
            console.error('Error converting canvas to blob:', error);
        });
}

function confirmImage() {
    emits('updateImage',{
        croppedImage: croppedImage.value,
        type: image.type,
        blob: rawBlob.value,
    });
    emits('updateSource', image);
}

</script>
<template>

    <Cropper
        v-if="image.src"
        :src="image.src"
        :type="image.type"
        class="mb-4"
        :default-position="image.coordinates"
        :default-size="image.size"
        :stencil-props="{
		aspectRatio: 3/4,
		movable: true,
		resizable: true
	}"
        @change="onChangeCrop"
    />

    <!-- Rules -->
    <div class="text-sm mt-2 my-4">
        <p class="max-w-xs">All photos will be manually reviewed before printing. Kindly follow the rules to ensure your photo does not get rejected.</p>
        <ul class="list-disc pl-4 mt-2">
            <li>No Digital Art</li>
            <li>No Human Faces</li>
            <li>No Animals</li>
            <li>No AI-Generated Content</li>
            <li>No NSFW Content</li>
            <li>We reserve the right to reject any submission.</li>
        </ul>
    </div>

    <div :class="{'grid grid-cols-2 gap-3': image.src}">
        <div v-if="image.src" class="w-full">
            <Button severity="success" label="Confirm" icon="pi pi-check" class="w-full" @click="confirmImage()"/>
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

</style>
