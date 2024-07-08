<script setup>
import FileUpload from 'primevue/fileupload';
import {reactive, ref} from "vue";
import {Cropper} from 'vue-advanced-cropper'
import 'vue-advanced-cropper/dist/style.css';
import pica from 'pica';
import Button from 'primevue/button';

const image = reactive({
    src: null,
    type: null,
});

const croppedImage = ref(null);
const rawBlob = ref(null);
const picaInstance = pica();

const emits = defineEmits(['updateImage'])

const onSelectFile = (event) => {
    console.log(event);
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
    }
}

const onChangeCrop = (event) => {
    const {coordinates, canvas} = event;
    coordinates.value = coordinates;
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

</script>
<template>
        <FileUpload mode="basic" accept="image/*" :auto="false" class="w-full" :maxFileSize="1000000"
                    @select="onSelectFile"
                    choose-label="Choose Image"
                    v-if="!image.src"/>
    <Cropper
        v-if="image.src"
        :src="image.src"
        :type="image.type"
        class="mb-4"
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
            <li>Only submit photos of fursuits in your possession.</li>
            <li>No humans in the photos.</li>
            <li>No explicit content.</li>
            <li>No drawings or illustrations.</li>
            <li>No AI-generated images.</li>
        </ul>
    </div>

    <div v-if="image.src">
        <Button label="Upload" icon="pi pi-upload" class="w-full" @click="emits('updateImage',{
            croppedImage: croppedImage,
            type: image.type,
            blob: rawBlob,
        })"/>
    </div>
</template>

<style scoped>

</style>
