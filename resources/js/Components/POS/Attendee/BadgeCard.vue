<script setup>
import { computed } from 'vue';
import dayjs from 'dayjs';
import { formatEuroFromCents } from '@/helpers.js';
import { ACTION_LABELS, badgeAction, statusPill } from '@/Components/POS/Attendee/badgeAction.js';

const props = defineProps({
    badge: Object,
    selected: {
        type: Boolean,
        default: false,
    },
    // Past-event badges are worked one at a time; they never join a bulk
    // payment or a bulk handout of the current event.
    selectable: {
        type: Boolean,
        default: true,
    },
    // Set for a badge from an earlier convention. It sits in the same list as
    // this year's, so it has to announce itself loudly enough that nobody hands
    // over a 2025 badge thinking it is the one they just printed.
    eventLabel: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['toggle', 'act', 'more']);

const action = computed(() => badgeAction(props.badge));
const pill = computed(() => statusPill(props.badge));

const canSelect = computed(() => props.selectable && action.value !== null);

const isDone = computed(() => props.badge.status_fulfillment === 'picked_up');
const isRejected = computed(() => props.badge.fursuit?.status === 'rejected');

// One accent for every "do this" button, the same blue the rest of the POS
// uses. Green stays reserved for status, so a colour never means two things.
const actionClass = 'pos-btn--primary';

const extras = computed(() => {
    const list = [];
    if (props.badge.dual_side_print) {
        list.push('double sided');
    }
    if (props.badge.extra_copy_of) {
        list.push('extra copy');
    }

    return list;
});
</script>

<template>
    <div
        class="pos-badge"
        :class="[
            selected ? 'pos-badge--picked' : '',
            isDone ? 'pos-badge--done' : '',
            isRejected ? 'pos-badge--bad' : '',
        ]"
    >
        <button
            type="button"
            class="pos-badge__pick"
            :disabled="!canSelect"
            :aria-pressed="selected"
            @click="canSelect && emit('toggle', badge)"
        >
            <span v-if="canSelect" class="pos-badge__check" aria-hidden="true">
                <i class="pi pi-check text-sm"></i>
            </span>

            <img
                v-if="badge.fursuit?.image_url"
                :src="badge.fursuit.image_url"
                :alt="`${badge.fursuit?.name} artwork`"
                class="pos-badge__img"
                loading="lazy"
            />
            <span v-else class="pos-badge__img pos-badge__img--empty">
                <i class="pi pi-image text-xl"></i>
            </span>

            <span class="pos-badge__body">
                <span class="pos-badge__name">
                    <span v-if="eventLabel" class="pos-badge__prev">{{ eventLabel }} · previous event</span>
                    <span class="pos-badge__nametext">{{ badge.fursuit?.name || 'Unnamed fursuit' }}</span>
                </span>
                <span class="pos-badge__meta">
                    <span class="pos-num" :class="eventLabel ? 'font-bold text-pos-warn' : ''">
                        {{ badge.custom_id || `#${badge.id}` }}
                    </span>
                    <span v-if="badge.fursuit?.species?.name">· {{ badge.fursuit.species.name }}</span>
                    <span v-for="extra in extras" :key="extra" class="pos-pill">{{ extra }}</span>
                </span>
                <span class="pos-badge__meta">
                    <span class="pos-pill" :class="pill.tone">{{ pill.text }}</span>
                    <span
                        class="pos-badge__price"
                        :class="badge.status_payment === 'unpaid' && badge.total > 0 ? 'text-pos-bad' : 'text-pos-muted'"
                    >
                        {{ formatEuroFromCents(badge.total ?? 0) }}
                    </span>
                    <span v-if="badge.status_payment === 'unpaid' && badge.total > 0" class="text-pos-bad font-semibold">
                        unpaid
                    </span>
                    <span v-if="isDone && badge.picked_up_at" class="text-pos-muted">
                        {{ dayjs(badge.picked_up_at).format('DD.MM. HH:mm') }}
                    </span>
                </span>
            </span>
        </button>

        <div class="pos-badge__act">
            <button
                v-if="action"
                type="button"
                class="pos-btn pos-badge__go"
                :class="actionClass"
                @click="emit('act', { badge, action })"
            >
                {{ ACTION_LABELS[action] }}
            </button>

            <button
                type="button"
                class="pos-btn pos-badge__more"
                :aria-label="`More actions for ${badge.fursuit?.name || 'badge'}`"
                @click="emit('more', badge)"
            >
                <i class="pi pi-ellipsis-v"></i>
            </button>
        </div>
    </div>
</template>
