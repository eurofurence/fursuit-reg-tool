<script setup>
import Layout from "@/Layouts/Layout.vue";
import {Link, Head, usePage, router} from '@inertiajs/vue3'
import Steps from 'primevue/steps';
import Fieldset from 'primevue/fieldset';
import InputText from "primevue/inputtext";
import Dropdown from "primevue/dropdown";
import Dialog from 'primevue/dialog';
import {useForm} from 'laravel-precognition-vue-inertia'
import InputSwitch from 'primevue/inputswitch';
import {computed, onMounted, reactive, ref} from "vue";
import Button from 'primevue/button';
import ImageUpload from "@/Components/BadgeCreator/ImageUpload.vue";
import Panel from 'primevue/panel';
import Tag from 'primevue/tag';
import dayjs from "dayjs";
import InputError from "@/Components/InputError.vue";
import Message from 'primevue/message';
import ConfirmDialog from "primevue/confirmdialog";

const deleteModalOpen = ref(null)

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
    upgrades: {
        doubleSided: props.badge.dual_side_print
    }
},{
    forceFormData: true,
})

onMounted(() => {
    previewImage.value = props.badge.fursuit.image_url;
})

function submit() {
    form.submit();
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
    if (props.badge.is_free_badge === false) {
        price += 2;
    }
    return price;
})

const latePrice = computed(() => {
    if (props.badge.apply_late_fee) {
        return 2;
    }
    return 0;
})

const total = computed(() => {
    if (props.badge.extra_copy_of) {
        return 2;
    }
    let total = basePrice.value + latePrice.value;
    if (form.upgrades.doubleSided && !props.badge.extra_copy_of) {
        total += 1;
    }
    return total;
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
    <!-- Fursuit Creator -->
    <div class="pt-8 px-6 xl:px-0">
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
                                 class="bg-primary-950 h-64 w-48 rounded-lg drop-shadow mx-auto md:mx-0 flex items-center justify-center cursor-pointer">
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
                            <InputSwitch v-model="form.catchEmAll" :disabled="!canEdit" id="catchEmAll"
                                         aria-describedby="catchEmAll-help"/>
                            <label for="catchEmAll">Participate in the Catch-Em-All Game</label>
                        </div>
                        <small
                            id="catchEmAll-help">Participate in the Catch-Em-All game to be catchable by other attendees.</small>
                    </div>
                </div>
                <!-- Catch Em All -->
                <!-- Publish -->
                <div>
                    <div>
                        <div class="flex flex-row gap-2">
                            <InputSwitch v-model="form.publish" :disabled="!canEdit" id="publish"
                                         aria-describedby="publish-help"/>
                            <label for="publish">Publish to Gallery</label>
                        </div>
                        <small
                            id="publish-help">Save your Fursuit Data and Publish your badge information in our Fursuiter gallery.</small>
                    </div>
                </div>
                <!-- End Publish -->
            </div>
            <!-- End Group 2 -->
            <!-- Paid Extras -->
            <div>
                <div class="">
                    <div class="mb-8 ">
                        <h2 class="text-lg font-semibold">Upgrades</h2>
                        <p>Get a spare copy or get an exclusive double printed badge!</p>
                    </div>
                    <div class="space-y-3">
                        <div class="flex flex-col md:flex-row gap-8 w-full">
                            <div class="flex gap-3">
                                <div class="flex flex-row gap-2 mt-3">
                                    <InputSwitch v-model="form.upgrades.doubleSided"
                                                 :disabled="!canEdit"
                                                 id="extra1"
                                                 aria-describedby="extra1-help"/>
                                </div>
                                <div>
                                    <label class="font-semibold block"  for="extra1">Double Sided Badge
                                        <Tag value="+1,00 €" v-if="!props.badge.extra_copy_of"></Tag>
                                    </label>
                                    <small
                                        id="extra1-help">By default our Badges are only Printed on one side. If you want to have a double sided badge, please select this option.</small>
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
                            <div v-if="form.upgrades.doubleSided && !props.badge.extra_copy_of"
                                 class="flex justify-between border-b border-dotted border-gray-900">
                                <span>Double Sided Badge</span>
                                <span>1,00 €</span>
                            </div>
                            <div v-if="form.upgrades.spareCopy || props.badge.extra_copy_of"
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
