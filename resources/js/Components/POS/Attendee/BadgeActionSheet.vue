<script setup>
import { computed } from 'vue';
import dayjs from 'dayjs';
import Dialog from 'primevue/dialog';
import { posDialogPt } from '@/Components/POS/posDialog.js';
import { formatEuroFromCents } from '@/helpers.js';
import { isHandoutable, isPayable, statusPill } from '@/Components/POS/Attendee/badgeAction.js';

/*
 * Everything a badge can do that is NOT its next step: reprint, undo a
 * handout, pay a single badge out of order. They live behind the ⋮ so a
 * mis-tap on the row cannot fire them, and they are listed as full-width
 * rows because the sheet is opened deliberately, one action at a time.
 */
const props = defineProps({
    badge: Object,
    show: Boolean,
});

const emit = defineEmits(['close', 'print', 'handout', 'undo', 'pay']);

const pill = computed(() => (props.badge ? statusPill(props.badge) : null));
const printed = computed(() => props.badge?.printed_at);
</script>

<template>
    <Dialog
        :visible="show"
        modal
        :header="badge?.fursuit?.name || 'Badge'"
        :style="{ width: '32rem' }"
        :pt="posDialogPt"
        @update:visible="emit('close')"
    >
        <template v-if="badge">
            <div class="flex items-center gap-3 mb-3">
                <img
                    v-if="badge.fursuit?.image_url"
                    :src="badge.fursuit.image_url"
                    :alt="`${badge.fursuit?.name} artwork`"
                    class="pos-badge__img"
                />
                <div class="min-w-0">
                    <div class="pos-num text-lg font-bold">{{ badge.custom_id || `#${badge.id}` }}</div>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="pos-pill" :class="pill.tone">{{ pill.text }}</span>
                        <span class="pos-num font-semibold">{{ formatEuroFromCents(badge.total ?? 0) }}</span>
                    </div>
                    <div class="text-xs text-pos-muted mt-1">
                        {{ printed ? `Printed ${dayjs(printed).format('DD.MM.YY HH:mm')}` : 'Never printed' }}
                        <template v-if="badge.picked_up_at">
                            · handed out {{ dayjs(badge.picked_up_at).format('DD.MM.YY HH:mm') }}
                        </template>
                    </div>
                </div>
            </div>

            <div class="pos-block pos-block--rows">
                <button
                    v-if="isPayable(badge)"
                    type="button"
                    class="pos-row pos-row--action"
                    @click="emit('pay', badge)"
                >
                    <span class="pos-row__glyph"><i class="pi pi-euro"></i></span>
                    <span class="pos-row__body">
                        <span class="pos-row__title">Pay this badge</span>
                        <span class="pos-row__sub">Start a checkout for {{ formatEuroFromCents(badge.total ?? 0) }}</span>
                    </span>
                </button>

                <button type="button" class="pos-row pos-row--action" @click="emit('print', badge)">
                    <span class="pos-row__glyph"><i class="pi pi-print"></i></span>
                    <span class="pos-row__body">
                        <span class="pos-row__title">{{ printed ? 'Reprint badge' : 'Print badge' }}</span>
                        <span class="pos-row__sub">Queues a new print job</span>
                    </span>
                </button>

                <button
                    v-if="isHandoutable(badge)"
                    type="button"
                    class="pos-row pos-row--action"
                    @click="emit('handout', badge)"
                >
                    <span class="pos-row__glyph"><i class="pi pi-check"></i></span>
                    <span class="pos-row__body">
                        <span class="pos-row__title">Hand out</span>
                        <span class="pos-row__sub">Mark as picked up</span>
                    </span>
                </button>

                <button
                    v-if="badge.status_fulfillment === 'picked_up'"
                    type="button"
                    class="pos-row pos-row--action pos-row--warn"
                    @click="emit('undo', badge)"
                >
                    <span class="pos-row__glyph"><i class="pi pi-undo"></i></span>
                    <span class="pos-row__body">
                        <span class="pos-row__title">Undo handout</span>
                        <span class="pos-row__sub">Back to ready for pickup</span>
                    </span>
                </button>
            </div>
        </template>

        <template #footer>
            <button type="button" class="pos-btn pos-btn--commit" @click="emit('close')">Close</button>
        </template>
    </Dialog>
</template>
