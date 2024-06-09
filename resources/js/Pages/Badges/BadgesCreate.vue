<script setup>
import Layout from "@/Layouts/Layout.vue";
import {Link, Head} from '@inertiajs/vue3'
import Steps from 'primevue/steps';
import Fieldset from 'primevue/fieldset';
import InputText from "primevue/inputtext";
import Dropdown from "primevue/dropdown";
import Dialog from 'primevue/dialog';
import {useForm} from 'laravel-precognition-vue-inertia'
import InputSwitch from 'primevue/inputswitch';
import {ref} from "vue";
import Button from 'primevue/button';
import ImageUpload from "@/Components/BadgeCreator/ImageUpload.vue";


defineOptions({
    layout: Layout
})

const props = defineProps({
    species: Array
})

const imageModalOpen = ref(false)
const previewImage = ref(null);

const form = useForm('post', route('badges.store'), {
    species: null,
    name: null,
    image: null,
    catchEmAll: true,
    publish: false
})
</script>

<template>
    <Head title="Order your Fursuit Badge"/>
    <Dialog v-model:visible="imageModalOpen" :dismissableMask="false" modal header="Upload Fursuit Picture" :style="{ width: '25rem' }">
        <span class="text-surface-600 dark:text-surface-0/70 block mb-5">Please upload a picture, you can crop the image after you uploaded it.</span>
        <ImageUpload @update-image="(event) => previewImage = event"></ImageUpload>
    </Dialog>
    <div class="pt-8 px-6 xl:px-0">
        <div class="mb-8">
            <h1 class="text-xl sm:text-2xl md:text-3xl font-semibold font-main">Eurofurence Fursuit Badge Creator</h1>
            <p>Welcome to our badge configurator, please enter all the details and options you would like!</p>
        </div>
        <!-- Group 1 -- Fursuit Details -->
        <div class="space-y-8">
            <!-- Image -->
            <div>
                <div class="mb-8">
                    <h2 class="text-lg font-semibold">Fursuit Image</h2>
                    <p>Upload an image of your fursuit to be displayed on the badge.</p>
                </div>
                <div class="block md:flex gap-6 justify-center">
                    <div v-if="!previewImage" @click="imageModalOpen = true" class="bg-gray-500 h-64 w-48 rounded-lg drop-shadow mx-auto md:mx-0 flex items-center justify-center cursor-pointer">
                        <div class="underline text-white mt-48">Upload Image</div>
                    </div>
                    <img v-else :src="previewImage" alt="" class="h-64 w-48 rounded-lg drop-shadow mx-auto md:mx-0">
                    <!-- Rules -->
                    <div class="text-sm mt-2">
                        <p class="max-w-xs">All photos will be manually reviewed before printing. Kindly follow the rules to ensure your photo does not get rejected.</p>
                        <ul class="list-disc pl-4 mt-2">
                            <li>Only submit photos of fursuits in your possession.</li>
                            <li>No humans in the photos.</li>
                            <li>No explicit content.</li>
                            <li>No drawings or illustrations.</li>
                            <li>No AI-generated images.</li>
                        </ul>
                    </div>
                </div>
            </div>
            <!-- End Image -->
            <div class="grid md:grid-cols-2 gap-6">
                <!-- Name -->
                <div>
                    <div class="flex flex-col gap-2">
                        <label for="name">Name</label>
                        <InputText id="name" aria-describedby="name-help"/>
                        <small id="name-help">Enter the name of the fursuit.</small>
                    </div>
                </div>
                <!-- End Name -->
                <!-- Species (Primevue Dropdown editable) -->
                <div>
                    <div class="flex flex-col gap-2">
                        <label for="species">Species</label>
                        <Dropdown v-model="form.species" id="species" aria-describedby="species-help" editable
                                  :options="species" optionLabel="name" placeholder="Select a Species"
                                  class="w-full md:w-14rem"/>
                        <small
                            id="species-help">Enter the species of the fursuit. You may select one of the existing ones or create a
                            <span v-tooltip.bottom="'Woof, Woof! You found an Easteregg.'">mew</span> Species!</small>
                    </div>
                </div>
                <!-- End Species -->
            </div>
            <div class="grid lg:grid-cols-2 gap-6">
            <!-- Catch Em All -->
            <div>
                <div>
                    <div class="flex flex-row gap-2">
                        <InputSwitch v-model="form.catchEmAll" id="catchEmAll" aria-describedby="catchEmAll-help"/>
                        <label for="catchEmAll">Join the Catch-Em-All Game</label>
                    </div>
                    <small
                        id="catchEmAll-help">Join the Catch-Em-All game to be catchable by other attendees.</small>
                </div>
            </div>
            <!-- Catch Em All -->
            <!-- Publish -->
            <div>
                <div>
                    <div class="flex flex-row gap-2">
                        <InputSwitch v-model="form.publish" id="publish" aria-describedby="publish-help"/>
                        <label for="publish">Publish to Gallery</label>
                    </div>
                    <small
                        id="publish-help">Publish your badge information publicly in our fursuiter gallery.</small>
                </div>
            </div>
            <!-- End Publish -->
            </div>
        </div>

    </div>
</template>

<style scoped>

</style>
