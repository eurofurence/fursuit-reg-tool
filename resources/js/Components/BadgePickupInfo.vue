<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowRight, MapPin } from '@lucide/vue';
import { Card, CardTitle } from '@/Components/UI/card';

/*
 * Pickup pointer for the badge list.
 *
 * This card used to carry the whole pickup story: the booth grid, the desk opening hours
 * and the explanation of both. It was a second copy of /pickup, which meant two places to
 * change when the desk retimed itself and two places to be wrong in. The booth split and
 * the hours now live on the pickup page only; this card says where to go and links there.
 */
defineProps({
    // Optional one-liner about when this particular badge can be collected.
    timing: { type: String, default: null },
});
</script>

<template>
    <Card class="gap-0 p-5">
        <CardTitle class="text-xl font-bold leading-tight mb-2 flex items-center gap-2">
            <MapPin class="size-5 text-primary" />
            <span>Where to pick up your badge</span>
        </CardTitle>
        <ul class="space-y-1 text-sm text-gray-600 list-disc pl-5">
            <li>At the badge desk in the Fursuit Lounge</li>
            <li>You do <strong>not</strong> need to bring your fursuit</li>
            <li v-if="timing">{{ timing }}</li>
        </ul>

        <Link
            :href="route('info.pickup')"
            class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-primary-600 hover:text-primary-700"
        >
            View pickup details
            <ArrowRight class="size-3.5" />
        </Link>
    </Card>
</template>
