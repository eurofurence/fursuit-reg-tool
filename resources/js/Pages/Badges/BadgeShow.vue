<script setup>
import { Head, router, usePage } from "@inertiajs/vue3";
import { computed } from 'vue';
import Button from '@/Components/UI/UiButton.vue';
import Card from '@/Components/UI/UiCard.vue';
import Tag from '@/Components/UI/UiTag.vue';
import Message from '@/Components/UI/UiMessage.vue';
import Layout from "@/Layouts/Layout.vue";
import { badgeStatusTags } from '@/badgeStatus.js';
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

/*
 * The tracker mirrors the fulfillment state machine one-to-one:
 * pending -> processing -> ready_for_pickup -> picked_up, with the fursuit
 * review sitting inside `pending`. Picked Up stays in the list during the event
 * too - dropping it used to leave the tracker with no final step, so a
 * collected badge looked unfinished.
 */
const progressSteps = computed(() => {
    const steps = [
        { key: 'pending', label: 'Ordered', icon: Plus },
        { key: 'approved', label: 'Reviewed', icon: Shield },
        { key: 'processing', label: 'Printing', icon: Printer },
        { key: 'ready_for_pickup', label: props.badge.is_free_badge ? 'Ready for pickup' : 'Pay & pick up', icon: Package },
        { key: 'picked_up', label: 'Picked up', icon: CheckCircle2 },
    ];

    // On site the fursuit is in front of us, so badges skip the review queue.
    return isDuringEvent.value ? steps.filter((step) => step.key !== 'approved') : steps;
});

// Order of the fulfillment states, used to compare "where are we" against a step.
const FULFILLMENT_ORDER = ['pending', 'processing', 'ready_for_pickup', 'picked_up'];

const fulfillmentIndex = computed(() => {
    const index = FULFILLMENT_ORDER.indexOf(props.badge.status_fulfillment);

    return index === -1 ? 0 : index;
});

function getStepStatus(step) {
    switch (step.key) {
        case 'pending':
            return 'completed'; // The badge exists, so it was ordered.
        case 'approved':
            // Past pending means the fursuit cleared review, whatever it says now.
            if (fulfillmentIndex.value > 0) return 'completed';

            return props.badge.fursuit.status === 'approved' ? 'completed' :
                   props.badge.fursuit.status === 'rejected' ? 'failed' : 'current';
        case 'processing':
            return props.badge.status_fulfillment === 'processing' ? 'current' :
                   fulfillmentIndex.value > 1 ? 'completed' : 'pending';
        case 'ready_for_pickup':
            return props.badge.status_fulfillment === 'ready_for_pickup' ? 'current' :
                   fulfillmentIndex.value > 2 ? 'completed' : 'pending';
        case 'picked_up':
            return props.badge.status_fulfillment === 'picked_up' ? 'completed' : 'pending';
        default:
            return 'pending';
    }
}

/*
 * How much of the connector between the first and last step circle is filled,
 * as a 0..1 scale factor. The circles sit at the ends of that track, so a step's
 * centre is at index / (count - 1).
 */
const progressLineScale = computed(() => {
    const steps = progressSteps.value;
    const lastReached = steps.reduce((reached, step, index) => {
        const status = getStepStatus(step);

        return status === 'completed' || status === 'current' || status === 'failed' ? index : reached;
    }, 0);

    return lastReached / Math.max(steps.length - 1, 1);
});


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
    return badgeStatusTags(badge);
}

function getNextStepExplanation() {
    const badge = props.badge;

    if (badge.status_fulfillment === 'picked_up') {
        return null;
    }

    if (badge.status_fulfillment === 'ready_for_pickup') {
        const payLine = badge.status_payment === 'unpaid'
            ? '<br>There is still something to pay, so bring a card: we only take card on site (Mastercard, Visa, Amex).'
            : '';

        return `<strong>Waiting at the desk</strong><br>Collect it at the badge desk in the Fursuit Lounge during opening hours.${payLine}`;
    }

    if (badge.status_fulfillment === 'processing') {
        return '<strong>Printing</strong><br>Your badge is on the printer. You cannot change it any more. We email you as soon as it is at the desk.';
    }

    if (badge.fursuit.status === 'rejected' && !isDuringEvent.value) {
        return '<strong>Rejected in review</strong><br>Something is off with your submission. Check your email for the details, then edit the badge and send it back in.';
    }

    if (badge.status_fulfillment === 'pending' && badge.fursuit.status === 'pending' && !isDuringEvent.value) {
        return '<strong>In review</strong><br>Our team is checking your fursuit submission. You get an email once that is done, and printing starts after it.';
    }

    if (badge.status_fulfillment === 'pending') {
        const event = page.props.event;
        const now = new Date();
        const massPrintedAt = event?.mass_printed_at ? new Date(event.mass_printed_at) : null;
        const eventStarted = event && new Date(event.starts_at) <= now;

        if (eventStarted) {
            return '<strong>Queued for printing</strong><br>We print on site, which takes a few minutes once the queue reaches your badge. We email you when it is ready. Badges ordered during the convention can be collected from the second convention day.';
        }

        if (massPrintedAt && massPrintedAt > now) {
            return `<strong>Queued for printing</strong><br>We print all badges on ${massPrintedAt.toLocaleDateString()}. Until then you can still edit yours; afterwards it is locked and waiting for you at the desk on the first convention day.`;
        }

        if (massPrintedAt) {
            return '<strong>Queued for printing</strong><br>The pre-print run is done, so your badge is printed on site and can be collected from the second convention day.';
        }

        return '<strong>Accepted</strong><br>Your submission is through. Your badge will be waiting for you at Eurofurence.';
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

    <div class="site-container py-12">
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
                        class="absolute top-6 left-6 right-6 h-0.5 origin-left bg-green-500 transition-all duration-500"
                        :style="{ transform: `scaleX(${progressLineScale})` }"
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
                                        <div v-if="badge.custom_id" class="flex justify-between">
                                            <span class="font-medium">Badge ID:</span>
                                            <span>{{ badge.custom_id }}</span>
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
                                                v-if="badge.fursuit.catch_em_all"
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
                                                v-if="badge.fursuit.published"
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
