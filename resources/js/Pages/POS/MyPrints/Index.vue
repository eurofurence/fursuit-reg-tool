<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, onMounted, onUnmounted } from "vue";
import POSLayout from "@/Layouts/POSLayout.vue";
import dayjs from "dayjs";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    batches: {
        type: Array,
        default: () => [],
    },
});

// The clerk opens this page precisely because they are waiting on a card, so it
// refreshes itself rather than asking them to.
let timer = null;

onMounted(() => {
    timer = setInterval(() => {
        router.reload({ only: ['batches'], preserveState: true, preserveScroll: true });
    }, 10000);
});

onUnmounted(() => clearInterval(timer));

const openBatches = computed(
    () => props.batches.filter((batch) => ! ['completed', 'cancelled'].includes(batch.status))
);

const finishedBatches = computed(
    () => props.batches.filter((batch) => ['completed', 'cancelled'].includes(batch.status))
);

function toneClass(batch) {
    if (batch.tone === 'bad') return 'pos-row--bad';
    if (batch.tone === 'good') return 'pos-row--good';

    return '';
}

function dismiss(batch) {
    router.post(route('pos.my-prints.dismiss', { printBatch: batch.id }), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => router.reload({ only: ['batches'], preserveState: true, preserveScroll: true }),
    });
}

function when(batch) {
    const stamp = batch.completed_at ?? batch.created_at;

    return stamp ? dayjs(stamp).format('HH:mm') : '';
}
</script>

<template>
    <Head>
        <title>POS - My Print Jobs</title>
    </Head>

    <div class="w-full flex-1 flex flex-col gap-2">
        <section class="pos-card">
            <div class="pos-card__head">
                <h1>Your print jobs</h1>
                <Link :href="route('pos.dashboard')" class="pos-btn pos-btn--sm">Back</Link>
            </div>

            <p v-if="! batches.length" class="text-sm text-pos-muted">
                You have not sent anything to a printer yet. Print a badge from the
                attendee page or from badge management and it shows up here.
            </p>

            <div v-else class="flex flex-col gap-4">
                <div v-if="openBatches.length">
                    <h2 class="pos-label mb-1">Still printing</h2>
                    <div class="pos-block pos-block--rows w-full">
                        <div v-for="batch in openBatches" :key="batch.id" class="pos-row" :class="toneClass(batch)">
                            <span class="pos-row__glyph"><i class="pi pi-print"></i></span>
                            <span class="pos-row__body">
                                <span class="pos-row__title">{{ batch.headline }}</span>
                                <span class="pos-row__sub">
                                    {{ batch.name }} · {{ batch.printed_count }}/{{ batch.total_jobs }} printed
                                    <template v-if="batch.failed_count"> · {{ batch.failed_count }} failed</template>
                                </span>
                                <span v-if="batch.pause_reason" class="pos-row__sub text-pos-bad font-semibold">
                                    {{ batch.pause_reason }}
                                </span>
                            </span>
                            <span class="pos-kcap">{{ batch.status_label }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="finishedBatches.length">
                    <h2 class="pos-label mb-1">Finished</h2>
                    <div class="pos-block pos-block--rows w-full">
                        <div
                            v-for="batch in finishedBatches"
                            :key="batch.id"
                            class="pos-row"
                            :class="[toneClass(batch), batch.dismissed ? 'pos-row--done' : '']"
                        >
                            <span class="pos-row__glyph">
                                <i :class="batch.tone === 'bad' ? 'pi pi-times-circle' : 'pi pi-check-circle'"></i>
                            </span>
                            <span class="pos-row__body">
                                <span class="pos-row__title">{{ batch.headline }}</span>
                                <span class="pos-row__sub">{{ batch.name }} · {{ when(batch) }}</span>
                            </span>
                            <button
                                v-if="! batch.dismissed"
                                type="button"
                                class="pos-btn pos-btn--sm"
                                @click="dismiss(batch)"
                            >
                                Clear
                            </button>
                        </div>
                    </div>
                </div>

                <!-- The cards themselves: this is the way back to the attendee
                     who is waiting for one of them. -->
                <div>
                    <h2 class="pos-label mb-1">Cards</h2>
                    <div class="pos-block pos-block--rows w-full">
                        <template v-for="batch in batches" :key="`cards-${batch.id}`">
                            <component
                                v-for="badge in batch.badges"
                                :is="badge.attendee_url ? Link : 'div'"
                                :key="`${batch.id}-${badge.id}`"
                                :href="badge.attendee_url || null"
                                class="pos-row"
                            >
                                <span class="pos-row__glyph"><i class="pi pi-id-card"></i></span>
                                <span class="pos-row__body">
                                    <span class="pos-row__title">
                                        {{ badge.custom_id || `Badge #${badge.id}` }}
                                        <span v-if="badge.attendee_id" class="pos-num">· #{{ badge.attendee_id }}</span>
                                    </span>
                                    <span class="pos-row__sub">
                                        {{ badge.attendee_name || 'Unknown attendee' }}
                                        <template v-if="badge.fursuit_name"> · {{ badge.fursuit_name }}</template>
                                        · {{ batch.status_label }}
                                    </span>
                                </span>
                                <span v-if="badge.attendee_url" class="pos-kcap">Open</span>
                            </component>
                        </template>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
