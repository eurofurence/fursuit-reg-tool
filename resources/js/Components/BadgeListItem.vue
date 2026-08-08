<script setup>
import { computed } from 'vue';
import Tag from '@/Components/UI/UiTag.vue';
import { formatEuroFromCents } from '../helpers.js';
import { badgeStatusTags } from '../badgeStatus.js';

/*
 * One badge as a row in the attendee badge list.
 *
 * Replaces the DataTable this list used to be: the table forced the image,
 * status tags and price into fixed columns that collapsed badly on phones,
 * where most attendees open this page.
 */
const props = defineProps({
    badge: { type: Object, required: true },
    // Archive rows (badges left over from earlier events) are muted and carry
    // the event name instead of a link affordance.
    archived: { type: Boolean, default: false },
});

const statuses = computed(() => badgeStatusTags(props.badge));
</script>

<template>
    <div class="flex items-center gap-4 py-4">
        <img
            :src="badge.fursuit.image_url"
            :alt="`${badge.fursuit.name} badge`"
            :class="[
                'w-16 h-16 sm:w-20 sm:h-20 object-cover rounded-lg border flex-shrink-0',
                archived ? 'opacity-75' : '',
            ]"
            loading="lazy"
        />

        <div class="flex-1 min-w-0">
            <div class="font-semibold truncate">{{ badge.fursuit.name }}</div>
            <div class="text-sm text-gray-600 truncate">{{ badge.fursuit.species.name }}</div>
            <div class="mt-0.5 text-xs text-gray-500">
                <span v-if="badge.custom_id">Badge {{ badge.custom_id }}</span>
                <span v-if="badge.custom_id && archived"> • </span>
                <span v-if="archived">{{ badge.fursuit.event.name }}</span>
            </div>

            <div class="mt-2 flex flex-wrap gap-1">
                <Tag
                    v-for="status in statuses"
                    :key="status.value"
                    :severity="status.severity"
                    :value="status.value"
                />
                <Tag v-if="badge.extra_copy" severity="secondary" value="Extra Copy" />
            </div>
        </div>

        <div class="flex items-center gap-3 flex-shrink-0">
            <div class="text-right">
                <div class="font-semibold">{{ formatEuroFromCents(badge.total) }}</div>
            </div>
            <i v-if="!archived" class="pi pi-chevron-right text-gray-400 text-sm"></i>
        </div>
    </div>
</template>
