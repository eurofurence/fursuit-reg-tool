<script setup>
import Layout from "@/Layouts/Layout.vue";
import { Head, usePage } from '@inertiajs/vue3'
import InputText from "primevue/inputtext";
import Dropdown from "primevue/dropdown";
import Dialog from 'primevue/dialog';
import { useForm } from 'laravel-precognition-vue-inertia'
import InputSwitch from 'primevue/inputswitch';
import { computed, reactive, ref } from "vue";
import Button from 'primevue/button';
import ImageUpload from "@/Components/BadgeCreator/ImageUpload.vue";
import Panel from 'primevue/panel';
import Tag from 'primevue/tag';
import dayjs from "dayjs";
import InputError from "@/Components/InputError.vue";
import Message from "primevue/message";

defineOptions({
    layout: Layout
})

const props = defineProps({
    species: Array,
    isFree: Boolean,
    freeBadgeCopies: Number,
})

const imageModalOpen = ref(false)
const previewImage = ref(null);
const imageSource = reactive({});

const form = useForm('post', route('badges.store'), {
    species: null,
    name: null,
    image: null,
    catchEmAll: true,
    publish: false,
    tos: false,
    upgrades: {
        spareCopy: props.freeBadgeCopies > 0,
    }
})

function submit() {
    form.submit();
}

function imageUpdatedEvent(image) {
    console.log(image);
    previewImage.value = image.croppedImage;
    form.image = new File([image.blob], 'fursuit.' + image.type, {
        type: image.type
    });
    imageModalOpen.value = false;
}

const basePrice = computed(() => {
    let price = 0;
    if (props.isFree === false) {
        price += 2;
    }
    return price;
})

const latePrice = computed(() => {
    // No late fees in the new system
    return 0;
})

const copiesPrice = computed(() => {
    let price = 0
    if (props.freeBadgeCopies > 0) {
        price += props.freeBadgeCopies * 2;
    } else if (form.upgrades.spareCopy) {
        price += 2;
    }
    return price;
})

const total = computed(() => {
    let total = basePrice.value + latePrice.value + copiesPrice.value;
    return total;
})
</script>

<template>

    <Head title="Order your Fursuit Badge" />
    <Dialog ke v-model:visible="imageModalOpen" :dismissableMask="false" modal header="Upload Fursuit Picture"
        :style="{ width: '25rem' }">
        <span class="text-surface-600 dark:text-surface-0/70 block mb-5">Please upload a picture, you can crop the image
            after you uploaded it.</span>
        <ImageUpload @update-image="imageUpdatedEvent" @update-source="args => imageSource = args"
            :image-source="imageSource"></ImageUpload>
    </Dialog>
    <!-- Fursuit Creator -->
    <div class="pt-8 px-6 xl:px-0 max-w-screen-lg mx-auto">
        <div class="mb-8">
            <h1 class="text-xl sm:text-2xl md:text-3xl font-semibold font-main">Eurofurence Fursuit Badge Creator</h1>
            <p>Welcome to our badge configurator, please enter all the details and options you would like!</p>
        </div>
        <Message v-if="new Date(usePage().props.event.mass_printed_at) < new Date()" severity="info" :closable="false">
            {{ "Late badge orders can be picked up starting from the 2nd convention day." }}
        </Message>
        <!-- Group 1 -- Fursuit Details -->
        <div class="space-y-8">
            <div class="md:border-2 md:shadow md:bg-white md:rounded-lg md:p-8">
                <div class="mb-8 ">
                    <h2 class="text-lg font-semibold">Your Eurofurence Fursuit Badge</h2>
                    <p>Please enter the Information below, this will be displayed on your Fursuit Badge.</p>
                </div>
                <div class="flex flex-col md:flex-row gap-8 w-full">
                    <!-- Image -->
                    <div class="w-48 mx-auto shrink-0">
                        <div class="block md:flex gap-6 justify-center mb-1">
                            <div v-if="!previewImage" @click="imageModalOpen = true"
                                class="bg-primary-600 h-64 w-48 rounded-lg drop-shadow mx-auto md:mx-0 flex items-center justify-center cursor-pointer">
                                <div class="text-primary-100 text-center text-sm px-4">
                                    Click/Tap here to upload a photo of your fursuit
                                </div>
                            </div>
                            <div v-else class="relative">
                                <div @click="imageModalOpen = true"
                                    class="absolute top-0 right-0 h-64 w-48 z-50 rounded-lg duration-200 hover:bg-gray-900/50 text-center opacity-0 hover:opacity-100 flex flex-col justify-end items-center cursor-pointer">
                                    <div class="text-white mb-2">Edit Image</div>
                                </div>
                                <img :src="previewImage" alt=""
                                    class="h-64 w-48 rounded-lg drop-shadow mx-auto md:mx-0 block z-25">
                            </div>
                        </div>
                        <div class="text-center text-xs text-gray-500">
                            <div>Only jpg/png</div>
                            <div>Min 240x340px</div>
                            <div>Max 8 MB</div>
                        </div>
                        <InputError :error="form.errors.image"></InputError>
                    </div>
                    <!-- End Image -->
                    <div class="flex flex-col gap-6 grow">
                        <!-- Name -->
                        <div>
                            <div class="flex flex-col gap-2">
                                <label for="name">Fursuit Name</label>
                                <InputText class="w-full" id="name" v-model="form.name" aria-describedby="name-help" />
                                <InputError :error="form.errors.name"></InputError>
                                <small id="name-help">Enter the name of the fursuit.</small>
                            </div>
                        </div>
                        <!-- End Name -->
                        <!-- Species (Primevue Dropdown editable) -->
                        <div>
                            <div class="flex flex-col gap-2">
                                <label for="species">Fursuit Species</label>
                                <Dropdown v-model="form.species" id="species" aria-describedby="species-help" editable
                                    :options="species" optionLabel="name" optionValue="name"
                                    placeholder="Select a Species" class="w-full" />
                                <InputError :error="form.errors.species"></InputError>
                                <small id="species-help">Enter the species of the fursuit. You may select one of the
                                    existing ones or create a
                                    <span v-tooltip.bottom="'Woof, Woof! You found an Easteregg.'">mew</span>
                                    Species!</small>
                            </div>
                        </div>
                        <!-- End Species -->
                    </div>
                </div>
            </div>
            <!-- End Fursuit Creator -->
            <!-- Group 2 -- Options -->
            <div class="grid lg:grid-cols-2 gap-6">
                <!-- Catch Em All -->
                <div>
                    <div>
                        <div class="flex flex-row gap-2">
                            <InputSwitch v-model="form.catchEmAll" id="catchEmAll" aria-describedby="catchEmAll-help" />
                            <label for="catchEmAll">Participate in the Catch-Em-All Game</label>
                        </div>
                        <small id="catchEmAll-help">Participate in the Catch-Em-All game to be catchable by other
                            attendees.</small>
                    </div>
                </div>
                <!-- Catch Em All -->
                <!-- Publish -->
                <div>
                    <div>
                        <div class="flex flex-row gap-2">
                            <InputSwitch v-model="form.publish" id="publish" aria-describedby="publish-help" />
                            <label for="publish">Publish to Gallery</label>
                        </div>
                        <small id="publish-help">Save your Fursuit Data and Publish your badge information in our
                            Fursuiter gallery.</small>
                    </div>
                </div>
                <!-- End Publish -->
            </div>
            <!-- End Group 2 -->
            <!-- Paid Extras -->
            <div :hidden="props.isFree">
                <div class="">
                    <div class="mb-8 ">
                        <h2 class="text-lg font-semibold">Upgrades</h2>
                        <p>Get a spare copy of your printed badge!</p>
                    </div>
                    <div class="space-y-3">
                        <div class="flex flex-col md:flex-row gap-8 w-full">
                            <div class="flex gap-3">
                                <div class="flex flex-row gap-2 mt-3">
                                    <InputSwitch v-model="form.upgrades.spareCopy" id="extra2"
                                        aria-describedby="extra2-help" :disabled="props.isFree" />
                                </div>
                                <div>
                                    <label class="font-semibold block" for="extra2">Spare Copy
                                        <Tag value="+2,00 €"></Tag>
                                    </label>
                                    <small id="extra2-help">Get a spare copy of your badge. This is useful if you want
                                        to have a backup or if you want to give it to a friend.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- End Paid Extras -->
            </div>
            <Panel header="Checkout">
                <template #header>
                    <div class="flex items-center gap-2">
                        <i class="pi pi-shopping-cart"></i>
                        <span class="font-semibold">Checkout</span>
                    </div>
                </template>
                <div class="flex flex-col gap-4">
                    <div class="mx-auto">
                        <!-- tOS Checkbox -->
                        <div class="flex items-center gap-2 mx-auto">
                            <div>
                                <InputSwitch v-model="form.tos" id="tos" aria-describedby="tos-help" />
                            </div>
                            <label :class="{ 'text-red-500 font-bold': form.errors.tos }" for="tos">I confirm that the
                                Information that I
                                have supplied is correct.</label>
                        </div>
                        <InputError class="mx-auto" :error="form.errors.tos"></InputError>
                    </div>
                    <!-- Total -->
                    <div class="max-w-sm w-full mx-auto space-y-2">
                        <div>
                            <!-- Options -->
                            <div class="flex justify-between border-b border-dotted border-gray-900">
                                <span>Base Price</span>
                                <span>{{ basePrice }},00 €</span>
                            </div>
                            <!-- Options -->
                            <div v-if="latePrice > 0" class="border-b border-dotted border-gray-900">
                                <div class="flex justify-between ">
                                    <span>Late Fee</span>
                                    <span>{{ latePrice }},00 €</span>
                                </div>
                                <small>Orders placed after the Preorder Deadline will be charged a late fee.</small>
                            </div>
                            <div v-if="form.upgrades.spareCopy"
                                class="flex justify-between mb-4 border-b border-dotted border-gray-900">
                                <span>Spare Copy{{ props.freeBadgeCopies > 1 ? " x" + props.freeBadgeCopies : ""
                                    }}</span>
                                <span>{{ copiesPrice }},00 €</span>
                            </div>
                            <!-- End Options -->
                            <div class="flex justify-between text-2xl border-b border-double border-gray-900">
                                <span class="flex gap-2 items-end">Total <p class="text-gray-500 whitespace-nowrap text-sm">{{ props.isFree ? '(already paid)' : '' }}</p></span>
                                <span>{{ total }},00 €</span>
                            </div>
                        </div>
                        <!-- Confirm Button -->
                        <Button label="Confirm Badge Order" icon="pi pi-check" @click="submit()"
                            :loading="form.processing" class="w-full" />
                    </div>
                </div>
            </Panel>
        </div>
    </div>
</template>

<style scoped></style>
