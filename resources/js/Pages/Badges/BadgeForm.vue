<script setup>
import Layout from "@/Layouts/Layout.vue";
import { Link, Head, router } from '@inertiajs/vue3'
import InputText from "primevue/inputtext";
import Dropdown from "primevue/dropdown";
import Dialog from 'primevue/dialog';
import { useForm } from 'laravel-precognition-vue-inertia'
import InputSwitch from 'primevue/inputswitch';
import { computed, onMounted, reactive, ref } from "vue";
import Button from 'primevue/button';
import ImageUpload from "@/Components/BadgeCreator/ImageUpload.vue";
import InputError from "@/Components/InputError.vue";
import Message from 'primevue/message';
import Card from 'primevue/card';
import Divider from 'primevue/divider';
import Tag from 'primevue/tag';
import { usePage } from '@inertiajs/vue3';

defineOptions({
    layout: Layout
})

const props = defineProps({
    // Common props
    species: Array,
    
    // Create mode props
    prepaidBadgesLeft: Number,
    
    // Edit mode props
    badge: Object,
    canDelete: Boolean,
    canEdit: Boolean,
    hasExtraCopies: Boolean,
})

// Determine if we're in edit mode
const isEditMode = computed(() => !!props.badge)
const pageTitle = computed(() => isEditMode.value ? 'Edit your Fursuit Badge' : 'Order your Fursuit Badge')

// Dialog states
const imageModalOpen = ref(false)
const previewImage = ref(null);
const imageSource = reactive({});
const consentDialogOpen = ref(false);
const consentType = ref(''); // 'catchEmAll' or 'gallery'
const galleryDataDialogOpen = ref(false);
const deleteModalOpen = ref(false);

// Form setup
const form = useForm(
    'post', 
    isEditMode.value ? route('badges.update', { badge: props.badge.id }) : route('badges.store'),
    isEditMode.value ? {
        _method: 'put',
        species: props.badge.fursuit.species.name,
        name: props.badge.fursuit.name,
        image: null,
        catchEmAll: props.badge.fursuit.catch_em_all,
        publish: props.badge.fursuit.published,
        tos: false,
        upgrades: {
            spareCopy: false,
        }
    } : {
        species: null,
        name: null,
        image: null,
        catchEmAll: false,
        publish: false,
        tos: false,
        upgrades: {
            spareCopy: false, // Remove automatic spare copy logic
        }
    },
    isEditMode.value ? { forceFormData: true } : {}
)

onMounted(() => {
    if (isEditMode.value) {
        previewImage.value = props.badge.fursuit.image_url;
    }
})

// Form handlers
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

function openImageModal() {
    if (!isEditMode.value || props.canEdit) {
        imageModalOpen.value = true;
    }
}

function deleteBadge() {
    router.delete(route('badges.destroy', { badge: props.badge.id }), {
        preserveScroll: true
    });
}

// Pricing calculations (for create mode)
const basePrice = computed(() => {
    if (isEditMode.value) {
        if (props.badge.is_free_badge === false && !props.badge.extra_copy_of) {
            return 3;
        }
        return 0;
    }
    
    let price = 0;
    // Check if user has prepaid badges left, otherwise charge 3€
    if (props.prepaidBadgesLeft === 0 || props.prepaidBadgesLeft === undefined) {
        price += 3;
    }
    return price;
})

const latePrice = computed(() => {
    if (isEditMode.value) {
        return props.badge.apply_late_fee ? 3 : 0;
    }
    return 0; // No late fees in new system
})

const copiesPrice = computed(() => {
    if (isEditMode.value) {
        return props.badge.extra_copy_of ? 2 : 0;
    }
    
    let price = 0;
    if (form.upgrades.spareCopy) {
        price += 2;
    }
    return price;
})

const total = computed(() => {
    if (isEditMode.value && props.badge.extra_copy_of) {
        return 2;
    }
    return basePrice.value + latePrice.value + copiesPrice.value;
})

// Edit mode conditions
const canShowUpgrades = computed(() => {
    return !isEditMode.value && (props.prepaidBadgesLeft === 0 || props.prepaidBadgesLeft === undefined);
})

const canEditFields = computed(() => {
    return !isEditMode.value || props.canEdit;
})
</script>

<template>
    <Head :title="pageTitle"/>
    
    <!-- Image Upload Dialog -->
    <Dialog v-model:visible="imageModalOpen" :dismissableMask="false" modal header="Upload Fursuit Picture"
            :style="{ width: '28rem' }">
        <span class="text-surface-600 dark:text-surface-0/70 block mb-5">
            Please upload a picture, you can crop the image after you uploaded it.
        </span>
        <ImageUpload @update-image="imageUpdatedEvent" @update-source="args => imageSource = args" :image-source="imageSource"/>
    </Dialog>
    
    <!-- Consent Dialog -->
    <Dialog v-model:visible="consentDialogOpen" :dismissableMask="false" modal 
            :header="consentType === 'catchEmAll' ? 'Catch-Em-All Game Consent' : 'Fursuit Gallery Consent'"
            :style="{ width: '35rem' }">
        <div class="mb-4">
            <p class="mb-4">
                Please ensure that your Fursuit Badge contains:
            </p>
            <ul class="list-disc pl-6 mb-4 space-y-1">
                <li>Pictures of real fursuiters only</li>
                <li>No AI-generated content</li>
                <li>No digital art</li>
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
                </p>
            </div>
        </div>
        <div class="flex justify-end mt-6">
            <Button label="Close" @click="galleryDataDialogOpen = false" />
        </div>
    </Dialog>

    <!-- Delete Confirmation Dialog -->
    <Dialog v-if="isEditMode" v-model:visible="deleteModalOpen" :dismissableMask="false" modal header="Delete Badge"
            style="max-width: 40rem;min-width:18rem;">
        <span class="text-surface-600 dark:text-surface-0/70 block mb-5">
            Are you sure you want to delete your badge?
        </span>
        <Message :closable="false" v-if="hasExtraCopies" severity="error">
            You have extra copies of this badge, they will be deleted too.
        </Message>
        <div class="flex justify-end gap-4 mt-4">
            <Button label="Cancel" @click="deleteModalOpen = false" severity="secondary"/>
            <Button label="Delete" @click="deleteBadge" icon="pi pi-trash" severity="danger"/>
        </div>
    </Dialog>

    <!-- Main Content -->
    <div class="min-h-screen py-8">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Header Section -->
            <div class="text-center mb-8">
                <h1 class="text-4xl font-bold text-gray-900 mb-2">
                    {{ isEditMode ? 'Edit' : 'Create' }} Your Fursuit Badge
                </h1>
                <p class="text-lg text-gray-600 max-w-2xl mx-auto">
                    {{ isEditMode ? 'Update your badge details and preferences' : 'Design your personalized Eurofurence fursuit badge with all the details you want' }}
                </p>
            </div>

            <!-- Status Messages -->
            <div class="mb-6">
                <Message v-if="isEditMode && canEdit && !badge.extra_copy_of" 
                         icon="pi pi-info-circle" severity="info" class="mb-4">
                    You can edit your badge until we start processing it.
                </Message>
                
                <Message v-else-if="isEditMode && badge.extra_copy_of" 
                         icon="pi pi-exclamation-triangle" severity="warn" class="mb-4">
                    Discounted Badge Clones cannot be edited. To make modifications, please edit the 
                    <Link class="underline" :href="route('badges.edit', {badge: badge.extra_copy_of})">Master Badge</Link>.
                </Message>
                
                <Message v-else-if="isEditMode && !canEdit" 
                         icon="pi pi-exclamation-triangle" severity="error" class="mb-4">
                    You can no longer edit your badge. Please contact our 
                    <a class="underline" target="_blank" href="https://help.eurofurence.org">support</a> if you need help.
                </Message>

                <Message v-if="!isEditMode && new Date(usePage().props.event.mass_printed_at) < new Date()"
                         severity="info" :closable="false" class="mb-4">
                    <template v-if="new Date() >= new Date(new Date(usePage().props.event.starts_at).getTime() + 24 * 60 * 60 * 1000)">
                        We will send you an email once your badge is ready. Processing usually takes ~30 minutes.
                    </template>
                    <template v-else>
                        Late badge orders can be picked up from the 2nd convention day.
                    </template>
                </Message>
            </div>

            <!-- Main Form Grid -->
            <div class="grid lg:grid-cols-3 gap-8">
                
                <!-- Left Column - Badge Details -->
                <div class="lg:col-span-2 space-y-6">
                    
                    <!-- Fursuit Details Card -->
                    <Card class="shadow-lg">
                        <template #title>
                            <div class="flex items-center gap-2">
                                <i class="pi pi-user text-primary-600"></i>
                                <span>Fursuit Details</span>
                            </div>
                        </template>
                        
                        <template #content>
                            <div class="flex flex-col md:flex-row gap-6">
                                
                                <!-- Image Upload Section -->
                                <div class="flex flex-col items-center">
                                    <div class="relative group">
                                        <div v-if="!previewImage" @click="openImageModal"
                                             class="w-48 h-64 bg-gradient-to-br from-primary-400 to-primary-600 rounded-xl shadow-lg cursor-pointer transition-all duration-300 hover:shadow-xl hover:scale-105 flex items-center justify-center">
                                            <div class="text-white text-center px-4 flex flex-col">
                                                <i class="pi pi-camera text-3xl mb-2 block"></i>
                                                <span class="text-sm font-medium">Upload Fursuit Photo</span>
                                            </div>
                                        </div>
                                        
                                        <div v-else class="relative">
                                            <img :src="previewImage" alt="Fursuit preview"
                                                 class="w-48 h-64 object-cover rounded-xl shadow-lg">
                                            <div v-if="canEditFields" @click="openImageModal"
                                                 class="absolute inset-0 bg-black bg-opacity-0 hover:bg-opacity-50 rounded-xl cursor-pointer transition-all duration-300 flex items-center justify-center opacity-0 hover:opacity-100">
                                                <div class="text-white text-center">
                                                    <i class="pi pi-pencil text-2xl mb-1 block"></i>
                                                    <span class="text-sm font-medium">Edit Image</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3 text-center text-sm text-gray-500">
                                        <div>JPG/PNG • Min 240×340px</div>
                                        <div>Max 8 MB</div>
                                    </div>
                                    <InputError :error="form.errors.image" class="mt-2"/>
                                </div>
                                
                                <!-- Form Fields -->
                                <div class="space-y-6 flex-1">
                                    <!-- Fursuit Name -->
                                    <div>
                                        <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                                            Fursuit Name
                                        </label>
                                        <InputText 
                                            id="name" 
                                            v-model="form.name" 
                                            :disabled="!canEditFields"
                                            class="w-full"
                                            fluid
                                            placeholder="Enter your fursuit's name"
                                        />
                                        <InputError :error="form.errors.name" class="mt-1"/>
                                        <small class="text-gray-500 mt-1 block">
                                            This will be displayed on your badge
                                        </small>
                                    </div>
                                    
                                    <!-- Species -->
                                    <div>
                                        <label for="species" class="block text-sm font-medium text-gray-700 mb-2">
                                            Species
                                        </label>
                                        <Dropdown 
                                            v-model="form.species" 
                                            id="species" 
                                            :disabled="!canEditFields"
                                            editable
                                            :options="species" 
                                            optionLabel="name" 
                                            fluid
                                            optionValue="name"
                                            placeholder="Select or enter a species"
                                            class="w-full"
                                        />
                                        <InputError :error="form.errors.species" class="mt-1"/>
                                        <small class="text-gray-500 mt-1 block">
                                            Choose from existing species or create a new one
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Card>
                    
                    <!-- Preferences Card -->
                    <Card class="shadow-lg">
                        <template #title>
                            <div class="flex items-center gap-2">
                                <i class="pi pi-cog text-primary-600"></i>
                                <span>Badge Preferences</span>
                            </div>
                        </template>
                        
                        <template #content>
                            <div class="grid sm:grid-cols-2 gap-6">
                                
                                <!-- Catch-Em-All -->
                                <div class="p-4 border border-gray-200 rounded-lg hover:border-primary-300 transition-colors">
                                    <div class="flex items-start gap-3">
                                        <InputSwitch 
                                            :model-value="form.catchEmAll" 
                                            @update:model-value="handleCatchEmAllChange"
                                            :disabled="!canEditFields"
                                            id="catchEmAll"
                                        />
                                        <div class="flex-1">
                                            <label for="catchEmAll" class="font-medium text-gray-900 cursor-pointer">
                                                Catch-Em-All Game
                                            </label>
                                            <p class="text-sm text-gray-600 mt-1">
                                                Participate in the convention game to be catchable by other attendees.
                                                <strong class="text-primary-600">For fursuiters only.</strong>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Gallery Publishing -->
                                <div class="p-4 border border-gray-200 rounded-lg hover:border-primary-300 transition-colors">
                                    <div class="flex items-start gap-3">
                                        <InputSwitch 
                                            :model-value="form.publish" 
                                            @update:model-value="handleGalleryChange"
                                            :disabled="!canEditFields"
                                            id="publish"
                                        />
                                        <div class="flex-1">
                                            <label for="publish" class="font-medium text-gray-900 cursor-pointer">
                                                Fursuit Gallery
                                            </label>
                                            <p class="text-sm text-gray-600 mt-1">
                                                Publish your fursuit in our online gallery for everyone to see.
                                                <strong class="text-primary-600">For fursuiters only.</strong>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Card>
                    
                    <!-- Upgrades Card (Create mode only) -->
                    <Card v-if="canShowUpgrades" class="shadow-lg">
                        <template #title>
                            <div class="flex items-center gap-2">
                                <i class="pi pi-plus-circle text-primary-600"></i>
                                <span>Upgrades</span>
                            </div>
                        </template>
                        
                        <template #content>
                            <div class="p-4 border border-gray-200 rounded-lg hover:border-primary-300 transition-colors">
                                <div class="flex items-start gap-3">
                                    <InputSwitch 
                                        v-model="form.upgrades.spareCopy" 
                                        id="spareCopy"
                                        :disabled="false"
                                    />
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-1">
                                            <label for="spareCopy" class="font-medium text-gray-900 cursor-pointer">
                                                Spare Copy
                                            </label>
                                            <Tag value="+2,00 €" severity="success"/>
                                        </div>
                                        <p class="text-sm text-gray-600">
                                            Get an additional copy of your badge. Perfect as a backup or to share with friends.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Card>
                </div>
                
                <!-- Right Column - Summary & Actions -->
                <div class="space-y-6">
                    
                    <!-- Order Summary Card -->
                    <Card class="shadow-lg sticky top-8">
                        <template #title>
                            <div class="flex items-center gap-2">
                                <i class="pi pi-shopping-cart text-primary-600"></i>
                                <span>{{ isEditMode ? 'Badge Summary' : 'Order Summary' }}</span>
                            </div>
                        </template>
                        
                        <template #content>
                            <div class="space-y-4">
                                
                                <!-- Price Breakdown -->
                                <div class="space-y-3">
                                    <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                        <span class="text-gray-600">Base Price</span>
                                        <span class="font-medium">{{ basePrice }},00 €</span>
                                    </div>
                                    
                                    <div v-if="latePrice > 0" class="flex justify-between items-center py-2 border-b border-gray-100">
                                        <div>
                                            <span class="text-gray-600">Late Fee</span>
                                            <p class="text-xs text-gray-500">Applied after deadline</p>
                                        </div>
                                        <span class="font-medium">{{ latePrice }},00 €</span>
                                    </div>
                                    
                                    <div v-if="form.upgrades.spareCopy || (isEditMode && badge.extra_copy_of)" 
                                         class="flex justify-between items-center py-2 border-b border-gray-100">
                                        <span class="text-gray-600">
                                            Spare Copy
                                        </span>
                                        <span class="font-medium">{{ copiesPrice }},00 €</span>
                                    </div>
                                </div>
                                
                                <Divider class="my-4"/>
                                
                                <!-- Total -->
                                <div class="flex justify-between items-center py-2">
                                    <span class="text-lg font-semibold text-gray-900">Total</span>
                                    <span class="text-2xl font-bold text-primary-600">{{ total }},00 €</span>
                                </div>
                                
                                <!-- Terms Acceptance (Create mode only) -->
                                <div v-if="!isEditMode" class="pt-4">
                                    <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                                        <InputSwitch v-model="form.tos" id="tos"/>
                                        <div class="flex-1">
                                            <label for="tos" :class="{'text-red-600 font-medium': form.errors.tos}" class="cursor-pointer text-sm">
                                                I confirm that the information I have supplied is correct.
                                            </label>
                                            <InputError :error="form.errors.tos" class="mt-1"/>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="pt-4 space-y-3">
                                    <Button 
                                        :label="isEditMode ? 'Save Changes' : 'Confirm Badge Order'" 
                                        :icon="isEditMode ? 'pi pi-save' : 'pi pi-check'"
                                        @click="submit()"
                                        :loading="form.processing" 
                                        :disabled="!isEditMode && !form.tos"
                                        class="w-full"
                                        size="large"
                                    />
                                    
                                    <Button v-if="isEditMode && canDelete" 
                                        label="Delete Badge"
                                        icon="pi pi-trash"
                                        @click="deleteModalOpen = true"
                                        severity="danger"
                                        outlined
                                        class="w-full"
                                    />
                                </div>
                            </div>
                        </template>
                    </Card>
                    
                    <!-- Having Issues Card -->
                    <Card class="shadow-lg">
                        <template #title>
                            <div class="flex items-center gap-2">
                                <i class="pi pi-exclamation-circle text-primary-600"></i>
                                <span>Having issues?</span>
                            </div>
                        </template>
                        <template #content>
                            <div class="space-y-3 text-sm text-gray-600">
                                <p>
                                    Some older devices or browsers may not support image uploads. This is because your image is cropped directly in your browser. If you have trouble, try using a different device or browser.
                                </p>
                                <p>
                                    Please avoid uploading very large files. Large images are cropped in your browser and may cause problems when printing your badge.
                                </p>
                            </div>
                        </template>
                    </Card>

                </div>
            </div>

            <!-- Back Button (Edit mode only) -->
            <div v-if="isEditMode" class="mt-6 text-center">
                <Button
                    @click="router.visit(route('badges.show', {badge: badge.id}))"
                    class="p-button-secondary"
                >
                    <template #icon>
                        <i class="pi pi-arrow-left mr-2"></i>
                    </template>
                    Back to Badge Details
                </Button>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Custom styles for better visual hierarchy */
.p-card {
    @apply border-0 bg-white/80 backdrop-blur-sm;
}

.p-card .p-card-title {
    @apply text-gray-900;
}

.p-card .p-card-content {
    @apply pt-0;
}

/* Smooth transitions for interactive elements */
.group:hover .transition-all {
    transform: translateY(-2px);
}

/* Better focus styles */
.p-inputtext:focus,
.p-dropdown:focus {
    @apply ring-2 ring-primary-500 ring-offset-2;
}
</style>
