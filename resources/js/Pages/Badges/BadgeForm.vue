<script setup>
import Layout from "@/Layouts/Layout.vue";
import { Link, Head, router } from '@inertiajs/vue3'
import { useForm } from '@inertiajs/vue3'
import { computed, onMounted, ref } from "vue";
import {
    ArrowLeft, Camera, Check, CircleAlert, CirclePlus, CircleX, Info, Loader2, Pencil, Save, Settings,
    ShoppingCart, Trash2, TriangleAlert, User,
} from '@lucide/vue';
import { Button, buttonVariants } from '@/Components/UI/button';
import { Alert, AlertDescription } from '@/Components/UI/alert';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/Components/UI/alert-dialog';
import { Badge } from '@/Components/UI/badge';
import { Card, CardTitle } from '@/Components/UI/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/UI/dialog';
import { Input } from '@/Components/UI/input';
import { Separator } from '@/Components/UI/separator';
import { Switch } from '@/Components/UI/switch';
import ImageUpload from "@/Components/BadgeCreator/ImageUpload.vue";
import InputError from "@/Components/InputError.vue";
import { usePage } from '@inertiajs/vue3';
import { formatPrintDate, massPrintRunIsOver } from '@/helpers.js';

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
// Set while the preview shows an uncropped original: {x, y, width, height, naturalWidth}.
// Null means the src is already the stored, cropped image.
const previewCrop = ref(null);
const imageSource = ref({});
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
        crop: null,
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
        crop: null,
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
    // The original file goes up untouched and the server crops it. The preview
    // fakes the crop with CSS so we never have to rasterise anything here.
    previewImage.value = image.src;
    previewCrop.value = image.naturalSize
        ? {...image.crop, naturalWidth: image.naturalSize.width}
        : null;
    form.image = image.file;
    form.crop = image.crop;
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

// Position the original image inside the 3:4 preview box so it shows exactly the
// selected rectangle. Percentages resolve against the box, so it stays correct
// at any container size.
const previewCropStyle = computed(() => {
    const crop = previewCrop.value;
    if (!crop || !crop.naturalWidth || !crop.width || !crop.height) {
        return null;
    }
    return {
        position: 'absolute',
        maxWidth: 'none',
        width: (crop.naturalWidth / crop.width) * 100 + '%',
        left: (-crop.x / crop.width) * 100 + '%',
        top: (-crop.y / crop.height) * 100 + '%',
    };
});

// Pricing calculations (for create mode)
const basePrice = computed(() => {
    if (isEditMode.value) {
        if (props.badge.is_free_badge === false && !props.badge.extra_copy_of) {
            return 5;
        }
        return 0;
    }

    let price = 0;
    // Check if user has prepaid badges left, otherwise charge 5€
    if (props.prepaidBadgesLeft === 0 || props.prepaidBadgesLeft === undefined) {
        price += 5;
    }
    return price;
})

const latePrice = computed(() => {
    // No late fees
    return 0;
})

const copiesPrice = computed(() => {
    if (isEditMode.value) {
        return props.badge.extra_copy_of ? 5 : 0;
    }

    let price = 0;
    if (form.upgrades.spareCopy) {
        price += 5;
    }
    return price;
})

const total = computed(() => {
    if (isEditMode.value && props.badge.extra_copy_of) {
        return 5;
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
    <Dialog v-model:open="imageModalOpen">
        <DialogContent class="sm:max-w-md max-h-[90vh] overflow-y-auto grid-cols-[minmax(0,1fr)]" @interact-outside.prevent>
            <DialogHeader>
                <DialogTitle>Upload Fursuit Picture</DialogTitle>
                <DialogDescription>
                    Please upload a picture, you can crop the image after you uploaded it.
                </DialogDescription>
            </DialogHeader>
            <ImageUpload @update-image="imageUpdatedEvent" @update-source="args => imageSource = args" :image-source="imageSource"/>
        </DialogContent>
    </Dialog>

    <!-- Consent Dialog -->
    <Dialog v-model:open="consentDialogOpen">
        <DialogContent class="sm:max-w-[35rem] max-h-[90vh] overflow-y-auto" @interact-outside.prevent>
            <DialogHeader>
                <DialogTitle>{{ consentType === 'catchEmAll' ? 'Catch-Em-All Game Consent' : 'Fursuit Gallery Consent' }}</DialogTitle>
                <DialogDescription class="text-foreground text-base">
                    Please ensure that your Fursuit Badge contains:
                </DialogDescription>
            </DialogHeader>
            <div>
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
                    <Button variant="link" class="p-0 h-auto text-sm mb-3" @click="showDataUsageInfo()">
                        Learn more about data usage
                    </Button>
                </div>
                <div v-if="consentType === 'catchEmAll'">
                    <p class="mb-4">
                        <strong>Leaderboard Visibility:</strong> In the catch-em-all game, your fursuit name, species, and identity username will be visible on the leaderboard.
                    </p>
                </div>
            </div>
            <DialogFooter>
                <Button variant="secondary" @click="declineConsent()">Decline</Button>
                <Button @click="acceptConsent()">Accept</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Data Usage Information Dialog -->
    <Dialog v-model:open="galleryDataDialogOpen">
        <DialogContent class="sm:max-w-[40rem] max-h-[90vh] overflow-y-auto" @interact-outside.prevent>
            <DialogHeader>
                <DialogTitle>How We Store Your Data</DialogTitle>
            </DialogHeader>
            <div class="space-y-4">
                <h3 class="font-semibold text-lg">Fursuit Gallery Data Storage</h3>
                <DialogDescription class="text-foreground text-base">
                    When you choose to publish your fursuit to our gallery, we collect and display the following information publicly:
                </DialogDescription>
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

                <Alert variant="warning">
                    <AlertDescription>
                        <p><strong>Note:</strong> You can withdraw consent at any time by contacting our support team.</p>
                    </AlertDescription>
                </Alert>
            </div>
            <DialogFooter>
                <Button @click="galleryDataDialogOpen = false">Close</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Delete Confirmation Dialog -->
    <AlertDialog v-if="isEditMode" v-model:open="deleteModalOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Delete Badge</AlertDialogTitle>
                <AlertDialogDescription>Are you sure you want to delete your badge?</AlertDialogDescription>
            </AlertDialogHeader>
            <Alert v-if="hasExtraCopies" variant="destructive">
                <CircleX/>
                <AlertDescription>You have extra copies of this badge, they will be deleted too.</AlertDescription>
            </Alert>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction :class="buttonVariants({ variant: 'destructive' })" @click="deleteBadge">
                    <Trash2/> Delete
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>

    <!-- Main Content -->
    <div class="min-h-screen py-8">
        <div class="site-container">
            
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
                <Alert v-if="isEditMode && canEdit && !badge.extra_copy_of" variant="info" class="mb-4">
                    <Info/>
                    <AlertDescription>You can edit your badge until we start processing it.</AlertDescription>
                </Alert>

                <Alert v-else-if="isEditMode && badge.extra_copy_of" variant="warning" class="mb-4">
                    <TriangleAlert/>
                    <AlertDescription>
                        <p>
                            Discounted Badge Clones cannot be edited. To make modifications, please edit the
                            <Link class="underline" :href="route('badges.edit', {badge: badge.extra_copy_of})">Master Badge</Link>.
                        </p>
                    </AlertDescription>
                </Alert>

                <Alert v-else-if="isEditMode && !canEdit" variant="destructive" class="mb-4">
                    <TriangleAlert/>
                    <AlertDescription>
                        <p>
                            You can no longer edit your badge. Please contact our
                            <a class="underline" target="_blank" href="https://help.eurofurence.org">support</a> if you need help.
                        </p>
                    </AlertDescription>
                </Alert>

                <Alert v-if="!isEditMode && massPrintRunIsOver(usePage().props.event)" variant="info" class="mb-4">
                    <Info/>
                    <AlertDescription>
                        <template v-if="new Date() >= new Date(new Date(usePage().props.event.starts_at).getTime() + 24 * 60 * 60 * 1000)">
                            We will send you an email once your badge is ready. Processing usually takes ~30 minutes.
                        </template>
                        <template v-else>
                            The printing deadline ({{ formatPrintDate(usePage().props.event.mass_printed_at) }})
                            has passed, so you can collect this badge from the second convention day.
                        </template>
                    </AlertDescription>
                </Alert>
            </div>

            <!-- Main Form Grid -->
            <div class="grid lg:grid-cols-3 gap-8">
                
                <!-- Left Column - Badge Details -->
                <div class="lg:col-span-2 space-y-6">
                    
                    <!-- Fursuit Details Card -->
                    <Card class="gap-3 p-5 shadow-lg">
                        <CardTitle class="text-xl font-bold leading-tight flex items-center gap-2">
                            <User class="size-5 text-primary"/>
                            <span>Fursuit Details</span>
                        </CardTitle>
                        <div>
                            <div class="flex flex-col md:flex-row gap-6">
                                
                                <!-- Image Upload Section -->
                                <div class="flex flex-col items-center">
                                    <div class="relative group">
                                        <div v-if="!previewImage" @click="openImageModal"
                                             class="w-48 h-64 bg-linear-to-br from-primary-400 to-primary-600 rounded-xl shadow-lg cursor-pointer transition-all duration-300 hover:shadow-xl hover:scale-105 flex items-center justify-center">
                                            <div class="text-white text-center px-4 flex flex-col">
                                                <Camera class="size-8 mb-2 mx-auto"/>
                                                <span class="text-sm font-medium">Upload Fursuit Photo</span>
                                            </div>
                                        </div>
                                        
                                        <div v-else class="relative">
                                            <div class="w-48 h-64 relative overflow-hidden rounded-xl shadow-lg bg-gray-100">
                                                <img :src="previewImage" alt="Fursuit preview"
                                                     :style="previewCropStyle"
                                                     :class="previewCropStyle ? '' : 'w-full h-full object-cover'">
                                            </div>
                                            <div v-if="canEditFields" @click="openImageModal"
                                                 class="absolute inset-0 bg-black/0 hover:bg-black/50 rounded-xl cursor-pointer transition-all duration-300 flex items-center justify-center opacity-0 hover:opacity-100">
                                                <div class="text-white text-center">
                                                    <Pencil class="size-6 mb-1 mx-auto"/>
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
                                        <Input
                                            id="name"
                                            v-model="form.name"
                                            :disabled="!canEditFields"
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
                                        <Input
                                            v-model="form.species"
                                            id="species"
                                            list="species-options"
                                            autocomplete="off"
                                            :disabled="!canEditFields"
                                            placeholder="Select or enter a species"
                                        />
                                        <datalist id="species-options">
                                            <option v-for="option in species" :key="option.name" :value="option.name"/>
                                        </datalist>
                                        <InputError :error="form.errors.species" class="mt-1"/>
                                        <small class="text-gray-500 mt-1 block">
                                            Choose from existing species or create a new one
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Card>
                    
                    <!-- Preferences Card -->
                    <Card class="gap-3 p-5 shadow-lg">
                        <CardTitle class="text-xl font-bold leading-tight flex items-center gap-2">
                            <Settings class="size-5 text-primary"/>
                            <span>Badge Preferences</span>
                        </CardTitle>
                        <div>
                            <div class="grid sm:grid-cols-2 gap-6">
                                
                                <!-- Catch-Em-All -->
                                <div class="p-4 border border-gray-200 rounded-lg hover:border-primary-300 transition-colors">
                                    <div class="flex items-start gap-3">
                                        <Switch
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
                                        <Switch
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
                        </div>
                    </Card>
                    
                    <!-- Upgrades Card (Create mode only) -->
                    <Card v-if="canShowUpgrades" class="gap-3 p-5 shadow-lg">
                        <CardTitle class="text-xl font-bold leading-tight flex items-center gap-2">
                            <CirclePlus class="size-5 text-primary"/>
                            <span>Upgrades</span>
                        </CardTitle>
                        <div>
                            <div class="p-4 border border-gray-200 rounded-lg hover:border-primary-300 transition-colors">
                                <div class="flex items-start gap-3">
                                    <Switch v-model="form.upgrades.spareCopy" id="spareCopy"/>
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-1">
                                            <label for="spareCopy" class="font-medium text-gray-900 cursor-pointer">
                                                Spare Copy
                                            </label>
                                            <Badge variant="success">+5,00 €</Badge>
                                        </div>
                                        <p class="text-sm text-gray-600">
                                            Get an additional copy of your badge. Perfect as a backup or to share with friends.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>
                
                <!-- Right Column - Summary & Actions -->
                <div class="space-y-6">
                    
                    <!-- Order Summary Card -->
                    <Card class="gap-3 p-5 shadow-lg sticky top-8">
                        <CardTitle class="text-xl font-bold leading-tight flex items-center gap-2">
                            <ShoppingCart class="size-5 text-primary"/>
                            <span>{{ isEditMode ? 'Badge Summary' : 'Order Summary' }}</span>
                        </CardTitle>
                        <div>
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
                                
                                <Separator class="my-4"/>
                                
                                <!-- Total -->
                                <div class="flex justify-between items-center py-2">
                                    <span class="text-lg font-semibold text-gray-900">Total</span>
                                    <span class="text-2xl font-bold text-primary-600">{{ total }},00 €</span>
                                </div>
                                
                                <!-- Terms Acceptance (Create mode only) -->
                                <div v-if="!isEditMode" class="pt-4">
                                    <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                                        <Switch v-model="form.tos" id="tos"/>
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
                                        @click="submit()"
                                        :disabled="form.processing || (!isEditMode && !form.tos)"
                                        class="w-full"
                                        size="lg"
                                    >
                                        <Loader2 v-if="form.processing" class="animate-spin"/>
                                        <Save v-else-if="isEditMode"/>
                                        <Check v-else/>
                                        {{ isEditMode ? 'Save Changes' : 'Confirm Badge Order' }}
                                    </Button>

                                    <Button v-if="isEditMode && canDelete"
                                        @click="deleteModalOpen = true"
                                        variant="outline"
                                        class="w-full border-destructive text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    >
                                        <Trash2/> Delete Badge
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </Card>
                    
                    <!-- Having Issues Card -->
                    <Card class="gap-3 p-5 shadow-lg">
                        <CardTitle class="text-xl font-bold leading-tight flex items-center gap-2">
                            <CircleAlert class="size-5 text-primary"/>
                            <span>Having issues?</span>
                        </CardTitle>
                        <div>
                            <div class="space-y-3 text-sm text-gray-600">
                                <p>
                                    Your photo is uploaded as you picked it and cropped on our servers, so the result does not depend on your browser. If the upload fails, check that the file is a JPG or PNG under 8 MB.
                                </p>
                                <p>
                                    Very large photos take longer to upload on convention WiFi. If an upload stalls, try again on a better connection or use a smaller file.
                                </p>
                            </div>
                        </div>
                    </Card>

                </div>
            </div>

            <!-- Back Button (Edit mode only) -->
            <div v-if="isEditMode" class="mt-6 text-center">
                <Button variant="secondary" @click="router.visit(route('badges.show', {badge: badge.id}))">
                    <ArrowLeft/> Back to Badge Details
                </Button>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Smooth transitions for interactive elements */
.group:hover .transition-all {
    transform: translateY(-2px);
}
</style>
