<script setup>
import Layout from "@/Layouts/Layout.vue";
import {Link, Head, router} from '@inertiajs/vue3'
import InputText from "primevue/inputtext";
import Dropdown from "primevue/dropdown";
import Dialog from 'primevue/dialog';
import {useForm} from 'laravel-precognition-vue-inertia'
import InputSwitch from 'primevue/inputswitch';
import {computed, onMounted, reactive, ref} from "vue";
import Button from 'primevue/button';
import ImageUpload from "@/Components/BadgeCreator/ImageUpload.vue";
import Panel from 'primevue/panel';
import InputError from "@/Components/InputError.vue";
import Message from 'primevue/message';

const deleteModalOpen = ref(null)
const consentDialogOpen = ref(false);
const consentType = ref(''); // 'catchEmAll' or 'gallery'
const galleryDataDialogOpen = ref(false);

import { useConfirm } from "primevue/useconfirm";

const confirm = useConfirm();

function deleteBadge() {
    router.delete(route('badges.destroy', {badge: props.badge.id}), {
        preserveScroll: true
    });
}

defineOptions({
    layout: Layout
})

const props = defineProps({
    species: Array,
    badge: Object,
    canDelete: Boolean,
    canEdit: Boolean,
    hasExtraCopies: Boolean
})

const imageModalOpen = ref(false)
const previewImage = ref(null);
const imageSource = reactive({});

const form = useForm('post', route('badges.update',{badge: props.badge.id}), {
    _method: 'put',
    species: props.badge.fursuit.species.name,
    name: props.badge.fursuit.name,
    image: null,
    catchEmAll: props.badge.fursuit.catch_em_all,
    publish: props.badge.fursuit.published,
},{
    forceFormData: true,
})

onMounted(() => {
    previewImage.value = props.badge.fursuit.image_url;
})

function submit() {
    form.submit();
}

function handleCatchEmAllChange(value) {
    if (value && !form.catchEmAll) {
        consentType.value = 'catchEmAll';
        consentDialogOpen.value = true;
    } else {
        form.catchEmAll = value;
    }
}

function handleGalleryChange(value) {
    if (value && !form.publish) {
        consentType.value = 'gallery';
        consentDialogOpen.value = true;
    } else {
        form.publish = value;
    }
}

function acceptConsent() {
    if (consentType.value === 'catchEmAll') {
        form.catchEmAll = true;
    } else if (consentType.value === 'gallery') {
        form.publish = true;
    }
    consentDialogOpen.value = false;
}

function declineConsent() {
    if (consentType.value === 'catchEmAll') {
        form.catchEmAll = false;
    } else if (consentType.value === 'gallery') {
        form.publish = false;
    }
    consentDialogOpen.value = false;
}

function showDataUsageInfo() {
    galleryDataDialogOpen.value = true;
}

function imageUpdatedEvent(image) {
    previewImage.value = image.croppedImage;
    form.image = new File([image.blob], 'fursuit.' + image.type, {
        type: image.type
    });
    imageModalOpen.value = false;
}

const basePrice = computed(() => {
    let price = 0;
    if (props.badge.is_free_badge === false && !props.badge.extra_copy_of) {
        price += 3;
    }
    return price;
})

const latePrice = computed(() => {
    if (props.badge.apply_late_fee) {
        return 3;
    }
    return 0;
})

const total = computed(() => {
    if (props.badge.extra_copy_of) {
        return 2;
    }
    return basePrice.value + latePrice.value;
})

function openImageModal() {
    if (props.canEdit) {
        imageModalOpen.value = true;
    }
}

</script>

<template>
    <Head title="Order your Fursuit Badge"/>
    <Dialog v-model:visible="imageModalOpen" :dismissableMask="false" modal header="Upload Fursuit Picture"
            :style="{ width: '25rem' }">
        <span
            class="text-surface-600 dark:text-surface-0/70 block mb-5">Please upload a picture, you can crop the image after you uploaded it.</span>
        <ImageUpload @update-image="imageUpdatedEvent" @update-source="args => imageSource = args" :image-source="imageSource"></ImageUpload>
    </Dialog>
    <!-- Delete Modal -->
    <Dialog v-model:visible="deleteModalOpen" :dismissableMask="false" modal header="Delete Badge"
            style="max-width: 40rem;min-width:18rem;">
        <span
            class="text-surface-600 dark:text-surface-0/70 block mb-5">Are you sure you want to delete your badge?</span>
        <Message :closable="false" v-if="hasExtraCopies" severity="error">You have extra copies of this badge, they will be deleted too.</Message>
        <div class="flex justify-end gap-4">
            <Button label="Cancel" @click="deleteModalOpen = false" class="w-1/2"/>
            <Button label="Delete" @click="deleteBadge" class="w-1/2" icon="pi pi-trash" severity="danger"/>
        </div>
    </Dialog>
    
    <!-- Consent Dialog -->
    <Dialog v-model:visible="consentDialogOpen" :dismissableMask="false" modal 
            :header="consentType === 'catchEmAll' ? 'Catch-Em-All Game Consent' : 'Fursuit Gallery Consent'"
            :style="{ width: '35rem' }">
        <div class="mb-4">
            <p class="mb-3 font-semibold">
                {{ consentType === 'catchEmAll' ? 'These features are for fursuiters only!' : 'Gallery Publishing Consent' }}
            </p>
            <p class="mb-4">
                Please ensure that your Fursuit Badge contains:
            </p>
            <ul class="list-disc pl-6 mb-4 space-y-1">
                <li>Pictures of real fursuiters only</li>
                <li>No AI-generated content</li>
                <li>No digital art</li>
                <li v-if="consentType === 'gallery'">No NSFW content (for gallery publishing)</li>
            </ul>
            <div v-if="consentType === 'gallery'">
                <p class="mb-2">
                    <strong>Data Usage:</strong> By publishing to the gallery, your identity username, badge image, fursuit name, and species will be published to our online fursuiters database, visible and searchable publicly for anyone to see.
                </p>
                <p class="mb-4">
                    If you participate in the catch-em-all game, we will also display how many times you have been caught.
                </p>
                <Button 
                    link 
                    label="Learn more about data usage" 
                    @click="showDataUsageInfo()"
                    class="p-0 text-sm mb-3"
                />
            </div>
            <div v-if="consentType === 'catchEmAll'">
                <p class="mb-4">
                    <strong>Leaderboard Visibility:</strong> In the catch-em-all game, your fursuit name, species, and identity username will be visible on the leaderboard.
                </p>
            </div>
        </div>
        <div class="flex justify-end gap-3">
            <Button label="Decline" @click="declineConsent()" severity="secondary" />
            <Button label="Accept" @click="acceptConsent()" />
        </div>
    </Dialog>
    
    <!-- Data Usage Information Dialog -->
    <Dialog v-model:visible="galleryDataDialogOpen" modal header="How We Store Your Data"
            :style="{ width: '40rem' }">
        <div class="space-y-4">
            <h3 class="font-semibold text-lg">Fursuit Gallery Data Storage</h3>
            <p>
                When you choose to publish your fursuit to our gallery, we collect and display the following information publicly:
            </p>
            <ul class="list-disc pl-6 space-y-2">
                <li><strong>Identity Username:</strong> Your registered username will be visible</li>
                <li><strong>Badge Image:</strong> The photo you upload will be displayed</li>
                <li><strong>Fursuit Name:</strong> The name you give your fursuit</li>
                <li><strong>Species:</strong> The species classification of your fursuit</li>
            </ul>
            
            <h4 class="font-semibold">Catch-Em-All Game Integration</h4>
            <p>
                If you also participate in the Catch-Em-All game, we will additionally display:
            </p>
            <ul class="list-disc pl-6 space-y-2">
                <li><strong>Catch Count:</strong> How many times other participants have caught you</li>
                <li><strong>Leaderboard Position:</strong> Your ranking in the game</li>
            </ul>
            
            <h4 class="font-semibold">Public Visibility</h4>
            <p>
                This information will be:
            </p>
            <ul class="list-disc pl-6 space-y-2">
                <li>Visible to anyone visiting our website</li>
                <li>Searchable by name, species, or username</li>
                <li>Available without requiring login or registration</li>
            </ul>
            
            <div class="bg-yellow-50 border border-yellow-200 rounded p-3 mt-4">
                <p class="text-sm">
                    <strong>Note:</strong> You can withdraw consent at any time by contacting our support team. 
                    Your data will then be removed from public display within 48 hours.
                </p>
            </div>
        </div>
        <div class="flex justify-end mt-6">
            <Button label="Close" @click="galleryDataDialogOpen = false" />
        </div>
    </Dialog>
    <!-- Fursuit Creator -->
    <div class="pt-8 px-6 xl:px-0 max-w-screen-lg mx-auto">
        <div class="mb-8 lg:flex justify-between">
            <div>
                <h1 class="text-xl sm:text-2xl md:text-3xl font-semibold font-main">Eurofurence Fursuit Badge Creator</h1>
                <p>Welcome to our badge configurator, please enter all the details and options you would like!</p>
            </div>
            <div>
                <!-- Delete Button -->
                <div v-if="canDelete" class="mt-4 text-right">
                    <Button
                        label="Delete Badge"
                        @click="deleteModalOpen = true"
                        icon="pi pi-trash" outlined severity="danger"
                            :loading="form.processing"/>
                </div>
            </div>
        </div>
        <Message v-if="canEdit && !badge.extra_copy_of" icon="pi pi-info-circle"
                 severity="info">Please note that you can only edit your badge until the pre-order deadline ends.
        </Message>
        <!-- Cloned Badges cannot be edited -->
        <Message v-else-if="badge.extra_copy_of" icon="pi pi-exclamation-triangle"
                 severity="warn">Discounted Badge Clones, cannot be edited. To make any modifications to this badge, please edit the
            <Link class="underline" :href="route('badges.edit',{badge: badge.extra_copy_of})">Master Badge</Link>
            .
        </Message>
        <Message v-else icon="pi pi-exclamation-triangle"
                 severity="error">You can no longer edit your badge, please contact our <a class="underline"
                                                                                           target="_blank"
                                                                                           href="https://help.eurofurence.org">support</a> if you need help.
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
                            <div v-if="!previewImage" @click="openImageModal"
                                 class="bg-primary-600 h-64 w-48 rounded-lg drop-shadow mx-auto md:mx-0 flex items-center justify-center cursor-pointer">
                                <div class="text-primary-100 text-center text-sm px-4">
                                    Click/Tap here to upload a photo of your fursuit
                                </div>
                            </div>
                            <div v-else class="relative">
                                <div
                                    v-if="canEdit"
                                    @click="openImageModal"
                                    class="absolute top-0 right-0 h-64 w-48 z-50 rounded-lg duration-200 hover:bg-gray-900/50 text-center opacity-0 hover:opacity-100 flex flex-col justify-end items-center cursor-pointer">
                                    <div class="text-white mb-2">Edit Image</div>
                                </div>
                                <img :src="previewImage" alt=""
                                     class="h-64 w-48 rounded-lg drop-shadow mx-auto md:mx-0 block z-25">
                            </div>
                        </div>
                        <div class="text-center text-xs text-gray-500" v-if="canEdit">
                            <div>Only jpg/png</div>
                            <div>Min 240x340px</div>
                            <div>Max 2MB (after crop)</div>
                        </div>
                        <InputError :error="form.errors.image"></InputError>
                    </div>
                    <!-- End Image -->
                    <div class="flex flex-col gap-6 grow">
                        <!-- Name -->
                        <div>
                            <div class="flex flex-col gap-2">
                                <label for="name">Fursuit Name</label>
                                <InputText :disabled="!canEdit" class="w-full" id="name" v-model="form.name"
                                           aria-describedby="name-help"/>
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
                                          :disabled="!canEdit"
                                          :options="species" optionLabel="name" optionValue="name"
                                          placeholder="Select a Species"
                                          class="w-full"/>
                                <InputError :error="form.errors.species"></InputError>
                                <small
                                    id="species-help">Enter the species of the fursuit. You may select one of the existing ones or create a
                                    <span
                                        v-tooltip.bottom="'Woof, Woof! You found an Easteregg.'">mew</span> Species!</small>
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
                            <InputSwitch 
                                :model-value="form.catchEmAll" 
                                @update:model-value="canEdit ? handleCatchEmAllChange : () => {}" 
                                :disabled="!canEdit" 
                                id="catchEmAll"
                                aria-describedby="catchEmAll-help"/>
                            <label for="catchEmAll">Participate in the Catch-Em-All Game</label>
                        </div>
                        <small
                            id="catchEmAll-help">Participate in the Catch-Em-All game to be catchable by other attendees. <strong>For fursuiters only.</strong></small>
                    </div>
                </div>
                <!-- Catch Em All -->
                <!-- Publish -->
                <div>
                    <div>
                        <div class="flex flex-row gap-2">
                            <InputSwitch 
                                :model-value="form.publish" 
                                @update:model-value="canEdit ? handleGalleryChange : () => {}" 
                                :disabled="!canEdit" 
                                id="publish"
                                aria-describedby="publish-help"/>
                            <label for="publish">Publish to Gallery</label>
                        </div>
                        <small
                            id="publish-help">Save your Fursuit Data and Publish your badge information in our Fursuiter gallery. <strong>For fursuiters only.</strong></small>
                    </div>
                </div>
                <!-- End Publish -->
            </div>
            <!-- End Group 2 -->
            <Panel header="Checkout">
                <template #header>
                    <div class="flex items-center gap-2">
                        <i class="pi pi-shopping-cart"></i>
                        <span class="font-semibold">Checkout</span>
                    </div>
                </template>
                <div class="flex flex-col gap-4">
                    <!-- Total -->
                    <div class="max-w-sm w-full mx-auto space-y-2">
                        <div>
                            <!-- Options -->
                            <div class="flex justify-between border-b border-dotted border-gray-900">
                                <span>Base Price</span>
                                <span>{{ basePrice }},00 €</span>
                            </div>
                            <!-- Options -->
                            <div v-if="badge.apply_late_fee && !props.badge.extra_copy_of"
                                 class="flex justify-between border-b border-dotted border-gray-900">
                                <span>Late Fee</span>
                                <span>{{ latePrice }},00 €</span>
                            </div>
                            <div v-if="props.badge.extra_copy_of"
                                 class="flex justify-between mb-4 border-b border-dotted border-gray-900">
                                <span>Spare Copy</span>
                                <span>2,00 €</span>
                            </div>
                            <!-- End Options -->
                            <div class="flex justify-between text-2xl border-b border-double border-gray-900">
                                <span>Total</span>
                                <span>{{ total }},00 €</span>
                            </div>
                        </div>
                        <!-- Confirm Button -->
                        <Button v-if="canEdit" label="Confirm Your Edit" icon="pi pi-check" @click="submit()"
                                :loading="form.processing" class="w-full"/>
                    </div>
                </div>
            </Panel>
        </div>
    </div>
</template>

<style scoped>

</style>
