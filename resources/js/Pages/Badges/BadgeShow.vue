<script setup>
import { Head, router, usePage } from "@inertiajs/vue3";
import { computed } from 'vue';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import Layout from "@/Layouts/Layout.vue";
import {
    Plus,
    Shield,
    Printer,
    Package,
    CheckCircle2,
    Calendar,
    AlertCircle,
    ArrowLeft,
    Check,
    X
} from 'lucide-vue-next';

defineOptions({
    layout: Layout
})

const props = defineProps({
    badge: Object,
    canEdit: Boolean,
});

const page = usePage();
const isDuringEvent = computed(() => {
    const event = page.props.event;
    if (!event) return false;

    const now = new Date();
    const startDate = new Date(event.starts_at);
    const endDate = new Date(event.ends_at);

    return startDate <= now && endDate >= now;
});

// Progress tracker steps (conditional based on event timing)
const progressSteps = computed(() => {
    const steps = [
        { key: 'pending', label: 'Order Placed', icon: Plus }
    ];

    // Only show review step if not during the event
    if (!isDuringEvent.value) {
        steps.push({ key: 'approved', label: 'Review', icon: Shield });
    }

    steps.push({ key: 'processing', label: 'Printing', icon: Printer });

    // Final step depends on badge type and event timing
    if (isDuringEvent.value) {
        const finalLabel = props.badge.is_free_badge ? 'Pickup' : 'Pay & Pickup';
        steps.push({ key: 'ready_for_pickup', label: finalLabel, icon: Package });
    } else {
        steps.push(
            { key: 'ready_for_pickup', label: 'Ready for Pickup', icon: Package },
            { key: 'picked_up', label: 'Picked Up', icon: CheckCircle2 }
        );
    }

    return steps;
});

function getStepStatus(step) {
    switch (step.key) {
        case 'pending':
            return 'completed'; // Always completed since badge exists
        case 'approved':
            // If we've progressed beyond pending fulfillment, fursuit must be approved
            if (['processing', 'ready_for_pickup', 'picked_up'].includes(props.badge.status_fulfillment)) {
                return 'completed';
            }
            return props.badge.fursuit.status === 'approved' ? 'completed' :
                   props.badge.fursuit.status === 'rejected' ? 'failed' : 'current';
        case 'processing':
            // During event, processing can start immediately after order; otherwise, after approval
            const prerequisitesMet = isDuringEvent.value
                ? true
                : props.badge.fursuit.status === 'approved';

            return props.badge.status_fulfillment === 'processing' ? 'current' :
                   ['ready_for_pickup', 'picked_up'].includes(props.badge.status_fulfillment) ? 'completed' :
                   prerequisitesMet ? 'pending' : 'pending';
        case 'ready_for_pickup':
            return props.badge.status_fulfillment === 'ready_for_pickup' ? 'current' :
                   props.badge.status_fulfillment === 'picked_up' ? 'completed' : 'pending';
        case 'picked_up':
            return props.badge.status_fulfillment === 'picked_up' ? 'completed' : 'pending';
        default:
            return 'pending';
    }
}

function getStepClass(status) {
    switch (status) {
        case 'completed':
            return 'bg-green-500 text-white border-green-500';
        case 'current':
            return 'bg-blue-500 text-white border-blue-500 ring-4 ring-blue-200';
        case 'failed':
            return 'bg-red-500 text-white border-red-500';
        default:
            return 'bg-gray-200 text-gray-500 border-gray-300';
    }
}

function getActionableStatuses(badge) {
    const statuses = [];

    // Only show approval status if not during event
    if (badge.status_fulfillment === 'pending' && badge.fursuit.status === 'pending' && !isDuringEvent.value) {
        statuses.push({
            value: 'Pending Approval',
            severity: 'warning'
        });
    }

    // Add fulfillment status
    if (badge.status_fulfillment === 'processing') {
        statuses.push({
            value: 'Printing',
            severity: 'info' // Blue
        });
    } else if (badge.status_fulfillment === 'ready_for_pickup') {
        const label = isDuringEvent.value
            ? (badge.is_free_badge ? 'Ready for Pickup' : 'Ready - Pay & Pickup')
            : 'Ready for Pickup';
        statuses.push({
            value: label,
            severity: 'warning' // Orange - action needed
        });
    } else if (badge.status_fulfillment === 'picked_up') {
        statuses.push({
            value: 'Picked Up',
            severity: 'success' // Green - only this is green
        });
    }

    return statuses;
}

function getNextStepExplanation() {
    const badge = props.badge;

    // If picked up - don't show any message
    if (badge.status_fulfillment === 'picked_up') {
        return null;
    }

    // If ready for pickup
    if (badge.status_fulfillment === 'ready_for_pickup') {
        if (badge.is_free_badge) {
            return '<strong>Ready for pickup</strong><br>Please come to our fursuit badge desk during opening hours to pickup your fursuit badge.';
        } else {
            return '<strong>Ready for pickup</strong><br>Please come to our fursuit badge desk during opening hours to pay and pickup your fursuit badge. We accept both card (preferred) and cash.';
        }
    }

    // If processing/printing
    if (badge.status_fulfillment === 'processing') {
        return '<strong>Printing</strong><br>Your Badge is currently being printed.';
    }

    // If rejected (only show during non-event times)
    if (badge.fursuit.status === 'rejected' && !isDuringEvent.value) {
        return '<strong>For Review Rejected</strong><br>There was an issue with your submission, please check your email for further details.';
    }

    // If pending approval (only show during non-event times)
    if (badge.status_fulfillment === 'pending' && badge.fursuit.status === 'pending' && !isDuringEvent.value) {
        return '<strong>Under review.</strong><br>Our team is reviewing your fursuit submission. You\'ll receive an email once the review is complete.';
    }

    // If approved but not yet in production queue
    if (badge.status_fulfillment === 'pending' && badge.fursuit.status === 'approved') {
        const event = page.props.event;
        const now = new Date();
        const massPrintingDone = event?.mass_printed_at && new Date(event.mass_printed_at) <= now;
        const eventStarted = event && new Date(event.starts_at) <= now;

        // Hide "Review Accepted" message entirely after event starts
        if (eventStarted) {
            return '<strong>Approved and queued.</strong><br>Your Badge is now queued for production, this usually takes a few minutes from the point of ordering. We will send you an email once your badge is ready for pickup. Please note that on the first convention day we are not printing any new badges.';
        } else if (massPrintingDone) {
            return `<strong>Approved and queued.</strong><br>We are going to print badges on ${new Date(event.mass_printed_at).toLocaleDateString()} after that date you won't be able to make changes to your badge.`;
        } else {
            return '<strong>For Review Accepted</strong><br>Your submission has been accepted, we will have your Fursuit Badge ready at Eurofurence!';
        }
    }

    // During event, for pending badges
    if (isDuringEvent.value && badge.status_fulfillment === 'pending') {
        return '<strong>Approved and queued.</strong><br>Your Badge is now queued for production, this usually takes a few minutes from the point of ordering. We will send you an email once your badge is ready for pickup. Please note that on the first convention day we are not printing any new badges.';
    }

    return 'Processing your badge request...';
}

function getMessageSeverity() {
    const badge = props.badge;

    if (badge.status_fulfillment === 'picked_up') {
        return 'success';
    }

    if (badge.status_fulfillment === 'ready_for_pickup') {
        return 'success';
    }

    if (badge.status_fulfillment === 'processing') {
        return 'info';
    }

    if (badge.fursuit.status === 'rejected') {
        return 'error';
    }

    // Default for pending/approved states
    return 'warn';
}

function cancelBadge() {
    if (confirm('Are you sure you want to cancel this badge? This action cannot be undone.')) {
        router.delete(route('badges.destroy', { badge: props.badge.id }), {
            onSuccess: () => {
                router.visit(route('badges.index'));
            }
        });
    }
}
</script>

<template>
    <Head :title="`Badge: ${badge.fursuit.name}`"/>

    <div class="max-w-screen-lg mx-auto py-12">
        <!-- Progress Tracker -->
        <Card class="mb-6">
            <template #title>
                <h2 class="text-2xl font-bold">Badge Progress</h2>
            </template>
            <template #content>
                <!-- Desktop Progress Tracker -->
                <div class="hidden md:block relative mb-8">
                    <!-- Progress Line Background -->
                    <div class="absolute top-6 left-6 right-6 h-0.5 bg-gray-300"></div>

                    <!-- Progress Line Filled -->
                    <div
                        class="absolute top-6 left-6 h-0.5 bg-green-500 transition-all duration-500"
                        :style="`width: ${(progressSteps.findIndex(step => getStepStatus(step) === 'current' || getStepStatus(step) === 'failed') / (progressSteps.length - 1)) * (100 - (12/16)*100)}%`"
                    ></div>

                    <!-- Steps -->
                    <div class="flex justify-between items-start">
                        <div
                            v-for="step in progressSteps"
                            :key="step.key"
                            class="flex flex-col items-center"
                        >
                            <!-- Step Circle -->
                            <div
                                :class="[
                                    'w-12 h-12 rounded-full flex items-center justify-center border-2 transition-all duration-200 bg-white z-10 relative',
                                    getStepClass(getStepStatus(step))
                                ]"
                            >
                                <component
                                    :is="step.icon"
                                    :size="20"
                                    :class="[
                                        getStepStatus(step) === 'completed' ? 'text-green-500' :
                                        getStepStatus(step) === 'current' ? 'text-blue-500' :
                                        getStepStatus(step) === 'failed' ? 'text-red-500' :
                                        'text-gray-500'
                                    ]"
                                />
                            </div>

                            <!-- Step Label -->
                            <span
                                :class="[
                                    'mt-2 text-sm font-medium text-center max-w-20',
                                    getStepStatus(step) === 'completed' ? 'text-green-700' :
                                    getStepStatus(step) === 'current' ? 'text-blue-700' :
                                    getStepStatus(step) === 'failed' ? 'text-red-700' :
                                    'text-gray-500'
                                ]"
                            >
                                {{ step.label }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Mobile Progress Tracker -->
                <div class="md:hidden mb-8">
                    <div
                        v-for="(step, index) in progressSteps"
                        :key="step.key"
                        class="relative flex items-center gap-4 pb-6 last:pb-0"
                    >
                        <!-- Step Circle -->
                        <div
                            :class="[
                                'w-12 h-12 rounded-full flex items-center justify-center border-2 transition-all duration-200 bg-white flex-shrink-0 relative z-10',
                                getStepClass(getStepStatus(step))
                            ]"
                        >
                            <component
                                :is="step.icon"
                                :size="20"
                                :class="[
                                    getStepStatus(step) === 'completed' ? 'text-green-500' :
                                    getStepStatus(step) === 'current' ? 'text-blue-500' :
                                    getStepStatus(step) === 'failed' ? 'text-red-500' :
                                    'text-gray-500'
                                ]"
                            />
                        </div>

                        <!-- Step Content -->
                        <div class="flex-1">
                            <span
                                :class="[
                                    'text-base font-medium block',
                                    getStepStatus(step) === 'completed' ? 'text-green-700' :
                                    getStepStatus(step) === 'current' ? 'text-blue-700' :
                                    getStepStatus(step) === 'failed' ? 'text-red-700' :
                                    'text-gray-500'
                                ]"
                            >
                                {{ step.label }}
                            </span>
                        </div>

                        <!-- Connecting Line (except for last item) -->
                        <div
                            v-if="index < progressSteps.length - 1"
                            class="absolute left-6 top-12 w-0.5 h-6 bg-gray-300 z-0"
                        ></div>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Status Message -->
        <div class="mb-6" v-if="getNextStepExplanation()">
            <Message :severity="getMessageSeverity()" class="text-sm" :closable="false">
                <span v-html="getNextStepExplanation()"></span>
            </Message>
        </div>

        <!-- Main Content -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left Column - Badge Details -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Badge Details Card -->
                <Card>
                    <template #title>
                        <h2 class="text-2xl font-bold">{{ badge.fursuit.name }}</h2>
                    </template>
                    <template #content>
                        <div class="flex gap-6">
                            <!-- Badge Image - Left Side -->
                            <div class="flex-shrink-0">
                                <img
                                    :src="badge.fursuit.image_url || '/images/placeholder.png'"
                                    :alt="badge.fursuit.name"
                                    class="w-48 h-64 object-cover rounded-xl shadow-lg"
                                />
                            </div>

                            <!-- Badge Information - Right Side -->
                            <div class="flex-1 space-y-4">
                                <!-- Current Status -->
                                <div>
                                    <h3 class="font-semibold text-lg mb-2">Current Status</h3>
                                    <div class="flex flex-wrap gap-2">
                                        <Tag
                                            v-for="status in getActionableStatuses(badge)"
                                            :key="status.value"
                                            :severity="status.severity"
                                            :value="status.value"
                                        />
                                        <Tag
                                            v-if="getActionableStatuses(badge).length === 0"
                                            severity="success"
                                            value="All Good!"
                                        />
                                    </div>
                                </div>

                                <!-- Badge Details -->
                                <div>
                                    <h3 class="font-semibold text-lg mb-2">Details</h3>
                                    <div class="space-y-2">
                                        <div class="flex justify-between">
                                            <span class="font-medium">Species:</span>
                                            <span>{{ badge.fursuit.species.name }}</span>
                                        </div>
                                        <div v-if="badge.extra_copy" class="flex justify-between">
                                            <span class="font-medium">Type:</span>
                                            <Tag severity="secondary" value="Extra Copy" size="small" />
                                        </div>
                                    </div>
                                </div>

                                <!-- Event Participation -->
                                <div>
                                    <h3 class="font-semibold text-lg mb-2">Event Participation</h3>
                                    <div class="space-y-3">
                                        <div class="flex items-center gap-3">
                                            <Check
                                                v-if="badge.fursuit.catchem_optin"
                                                :size="20"
                                                class="text-green-600"
                                            />
                                            <X
                                                v-else
                                                :size="20"
                                                class="text-gray-400"
                                            />
                                            <span class="font-semibold">Catch-Em-All</span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <Check
                                                v-if="badge.fursuit.gallery_optin"
                                                :size="20"
                                                class="text-green-600"
                                            />
                                            <X
                                                v-else
                                                :size="20"
                                                class="text-gray-400"
                                            />
                                            <span class="font-semibold">Fursuit Gallery</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </Card>
            </div>

            <!-- Right Column - Instructions -->
            <div class="space-y-6">
                <!-- Action Buttons -->
                <div class="space-y-3" v-if="canEdit">
                    <Button
                        label="Edit Badge"
                        icon="pi pi-pencil"
                        @click="router.visit(route('badges.edit', {badge: badge.id}))"
                        class="w-full"
                        size="large"
                    />
                    <Button
                        label="Cancel Badge"
                        icon="pi pi-trash"
                        @click="cancelBadge"
                        severity="danger"
                        outlined
                        class="w-full"
                    />
                </div>

                <!-- Having Issues Card -->
                <Card class="shadow-lg">
                    <template #title>
                        <div class="flex items-center gap-2">
                            <AlertCircle :size="20" class="text-primary-600" />
                            <span>Having issues?</span>
                        </div>
                    </template>
                    <template #content>
                        <div class="space-y-3 text-sm text-gray-600">
                            <p>
                                If you have any questions about your badge status or pickup process, please visit the Fursuit Lounge during convention hours or contact our staff.
                            </p>
                            <p>
                                For technical issues with the badge system, please report them to our support team.
                            </p>
                        </div>
                    </template>
                </Card>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="mt-6 text-center">
            <Button
                @click="router.visit(route('badges.index'))"
                class="p-button-secondary"
            >
                <template #icon>
                    <ArrowLeft :size="16" class="mr-2 text-current" />
                </template>
                Back to Badges
            </Button>
        </div>
    </div>
</template>

<style scoped>
.relative {
    position: relative;
}

.absolute {
    position: absolute;
}
</style>
