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
const coordinates = ref(null);
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
            croppedImage.value = URL.createObjectURL(blob);
            console.log('Cropped Image:', croppedImage.value);
        })
        .catch(error => {
            console.error('Error converting canvas to blob:', error);
        });
}

</script>
<template>
    <FileUpload mode="basic" accept="image/*" :auto="false" :maxFileSize="1000000" @select="onSelectFile"
                v-if="!image.src"/>
    <Cropper
        v-if="image.src"
        :src="image.src"
        :type="image.type"
        :stencil-props="{
		aspectRatio: 3/4,
		movable: true,
		resizable: true
	}"
        @change="onChangeCrop"
    />

    <div>
        <Button label="Upload" icon="pi pi-upload" class="w-full" @click="emits('updateImage',croppedImage)"/>
    </div>
</template>

<style scoped>

</style>
