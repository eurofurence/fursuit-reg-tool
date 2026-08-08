<script setup>
import { Head, router, usePage } from "@inertiajs/vue3";
import { computed } from 'vue';
import Button from '@/Components/UI/UiButton.vue';
import Card from '@/Components/UI/UiCard.vue';
import Tag from '@/Components/UI/UiTag.vue';
import Message from '@/Components/UI/UiMessage.vue';
import Layout from "@/Layouts/Layout.vue";
import BadgePickupInfo from '@/Components/BadgePickupInfo.vue';
import { badgeStatusTags } from '@/badgeStatus.js';
import {
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

// Same rule as the badge list: the pickup card appears once the badge is on its way to
// the desk, not while it is still an order that can be edited.
const showPickupInfo = computed(() =>
    ['processing', 'ready_for_pickup'].includes(props.badge.status_fulfillment)
);

/*
 * There is deliberately no step-by-step progress tracker here. The real route a
 * badge takes changes across the event (pre-print run, on-site printing, printed
 * on demand at the desk), so a fixed set of steps was wrong for most attendees
 * and read as "not printed yet" for badges that were already waiting for them.
 * The status message below is the single source of truth instead.
 */

function getActionableStatuses(badge) {
    return badgeStatusTags(badge);
}

// "1 August 2026", the same shape BuildsBadgeMail::collectionAnswer() sends by mail. The default
// locale format would print the deadline as 8/1/2026 for half the attendees and 1/8/2026 for the
// other half, which is the one date on the page nobody may misread.
function formatPrintDate(date) {
    return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
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
            return '<strong>Queued for printing</strong><br>We email you as soon as it is at the desk. Collect it from the second convention day.';
        }

        if (massPrintedAt && massPrintedAt > now) {
            return `<strong>Queued for printing</strong><br>We print all badges on ${formatPrintDate(massPrintedAt)}. Until then you can still edit yours. Collect it from the first convention day.`;
        }

        if (massPrintedAt) {
            return `<strong>Queued for printing</strong><br>The printing deadline (${formatPrintDate(massPrintedAt)}) has passed, so you can collect it from the second convention day.`;
        }

        // No print date set: the run has not been scheduled, so it is still ahead and this badge is
        // in it. Same reading as a future date, minus the date it cannot name.
        return '<strong>Queued for printing</strong><br>You can still edit it. Collect it from the first convention day.';
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

                                        <!--
                                            The reviewer's veto over both switches above. It is
                                            deliberately not framed as an error: the badge is
                                            approved, printed and handed out, and only the two
                                            public surfaces are closed. Without this the two
                                            crosses above would look like the attendee's own
                                            setting and the mail explaining them like a mistake.
                                        -->
                                        <div
                                            v-if="badge.fursuit.publication_blocked_at"
                                            class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm"
                                        >
                                            <p class="font-semibold text-amber-900">Not shown in the gallery or the game</p>
                                            <p v-if="badge.fursuit.publication_block_reason" class="mt-1 text-amber-800">
                                                {{ badge.fursuit.publication_block_reason }}
                                            </p>
                                            <p class="mt-1 text-amber-800">
                                                Your badge itself is approved and will be waiting for you at the desk. To be
                                                in the gallery and the game, submit a photo of your costume before we print.
                                            </p>
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
                <!-- Same card as the badge list, and like there it only points at /pickup:
                     the booth split and the desk hours live on that page alone. -->
                <BadgePickupInfo v-if="showPickupInfo"/>

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
