<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import dayjs from 'dayjs';
import Layout from '@/Layouts/Layout.vue';
import { formatEuroFromCents } from '@/helpers.js';

defineOptions({ layout: Layout });

const props = defineProps({
    event: { type: Object, default: null },
    badgePrice: { type: Number, required: true },
    freeBadgeDeadline: { type: String, default: null },
});

const price = computed(() => formatEuroFromCents(props.badgePrice));

const deadline = computed(() =>
    props.freeBadgeDeadline ? dayjs(props.freeBadgeDeadline).format('D MMMM YYYY') : null
);

/*
 * The price and the free-badge deadline are substituted from live data, so this page
 * cannot drift from what the badge actually costs. Everything else here is policy:
 * the Code of Conduct wording, what the desk accepts and where it stands come from the
 * team, so check with them before rewording rather than paraphrasing from memory.
 */
const entries = computed(() => [
    {
        q: 'How do I get a fursuit badge?',
        parts: [
            `One fursuit badge is included with your convention registration. It is free as long as you submit your badge details before ${deadline.value ?? 'the submission deadline'}.`,
            'That deadline exists because we cannot print badges on demand. Everything submitted in time goes into one print run before the convention, which is what makes the included badge possible.',
            `You can book further prepaid badges through the registration system, and you can order additional badges here for ${price.value} each.`,
        ],
    },
    {
        q: 'How do I pay?',
        parts: [
            `The badge included with your registration costs you nothing. Any badge beyond that is ${price.value}.`,
            'Whatever is still outstanding is settled at the badge desk. Payment there is cashless: most major credit and debit card brands work, as do mobile wallets such as Apple Pay and Google Pay.',
        ],
    },
    {
        q: 'Where do I pick my badge up?',
        parts: [
            'At the badge desk in the Fursuit Lounge.',
            'On day one we can only hand out badges that are already Ready for Pickup. Your badge page shows that status, so you can check before you walk over.',
        ],
        link: { label: 'Check my badges', route: 'badges.index' },
    },
    {
        q: 'Do I need to bring my fursuit to pick my badge up?',
        parts: [
            'No. The fursuit badge is a keepsake, so there is nothing to try on and nothing to match up at the desk. Come as you are.',
        ],
    },
    {
        q: 'Do I still have to wear my convention badge while in fursuit?',
        parts: [
            'In the convention-exclusive areas everyone has to have their issued convention badge on display.',
            'Fursuiters are the exception: you do not have to wear it openly, but you must have it with you, in a pocket or a bag, so you can show it when asked.',
        ],
    },
    {
        q: 'Why does my fursuit badge have to be approved?',
        parts: [
            'Every badge is reviewed for compliance with the Eurofurence Code of Conduct, and to check whether it can also be shown in the gallery and used in Fursuit Catch-Em-All.',
            'The gallery and the game only take badges that show a costume. If you use digital art rather than a photo of your suit, your badge can be rejected from being published there, because the gallery is meant to stay a gallery of real suits.',
            'That rejection is only about being published. If your badge follows the Code of Conduct you still get it handed out at the desk; it simply will not appear in the gallery and cannot be caught in the game.',
        ],
        link: { label: 'How Catch-Em-All works', route: 'info.catch-em-all' },
    },
]);
</script>

<template>
    <Head title="FAQ"/>

    <div class="site-container pt-6">
        <h1 class="text-2xl font-bold">Frequently asked questions</h1>
        <p class="text-gray-600 mt-1">
            Ordering, paying for and collecting your fursuit badge
            <template v-if="event"> at {{ event.name }}</template>.
        </p>

        <!-- CSS columns rather than a grid: answers differ wildly in length, and a grid
             leaves a hole beside every short card. Columns let the cards reflow into each
             other so the page packs tight. break-inside-avoid keeps a card whole. -->
        <div class="mt-5 columns-1 md:columns-2 gap-3">
            <article
                v-for="entry in entries"
                :key="entry.q"
                class="rounded-lg bg-white shadow-sm p-5 mb-3 break-inside-avoid"
            >
                <h2 class="flex gap-2.5 font-bold">
                    <span class="text-primary-500 shrink-0" aria-hidden="true">Q:</span>
                    <span>{{ entry.q }}</span>
                </h2>
                <div class="flex gap-2.5 mt-2 text-gray-600 text-sm">
                    <span class="text-gray-400 font-bold shrink-0" aria-hidden="true">A:</span>
                    <div class="flex flex-col gap-2">
                        <p v-for="part in entry.parts" :key="part">{{ part }}</p>
                        <Link
                            v-if="entry.link"
                            :href="route(entry.link.route)"
                            class="font-semibold text-primary-500 underline w-fit"
                        >
                            {{ entry.link.label }}
                        </Link>
                    </div>
                </div>
            </article>
        </div>

        <p class="text-sm text-gray-500 mt-6">
            Still stuck? The badge desk in the Fursuit Lounge can help you on site, and the convention's
            <a href="https://help.eurofurence.org" target="_blank" rel="noopener" class="underline">help pages</a>
            cover everything that is not about fursuit badges.
        </p>
    </div>
</template>
