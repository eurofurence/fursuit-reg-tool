<script setup>

import {Head, usePage} from "@inertiajs/vue3";
import Button from 'primevue/button';
import dayjs from "dayjs";
import {Link} from "@inertiajs/vue3";
import Message from 'primevue/message';
import {computed} from "vue";
import Layout from "@/Layouts/Layout.vue";

defineOptions({
    layout: Layout
})

const preorderPeriodEnded = computed(() => {
    return dayjs().isAfter(dayjs('2022-08-15'));
});

const eventEnded = computed(() => {
    return usePage().props.event === null;
});

const messages = computed(() => {
    if (eventEnded.value === true) {
        return {
            hero_title: "Come back next year!",
            hero_subtitle: "The event has ended, but we will start preorders for next year's Eurofurence soon. Please check back later for more information",
            message: null,
            showButtons: false
        };
    } else if (preorderPeriodEnded === true) {
        return {
            hero_title: "It's not too late!",
            hero_subtitle: "The preorder period has ended. Any fursuit badge orders placed now will include a late printing fee of 2 €.",
            message: {
                type: "warn",
                text: "The preorder period has ended. Any fursuit badge orders placed now will include a late printing fee of 2 €."
            },
            showButtons: true
        };
    } else {
        return {
            hero_title: "Claim your Badge today!",
            hero_subtitle: "With any valid convention ticket, you can preorder your first preorder fursuit badge for free. Any additional fursuit badges can be ordered for just 2 € per badge.",
            message: {
                type: 'info',
                text: "Please note that you can claim your free fursuit badge only until " + dayjs('2022-08-15').format('MMMM D, YYYY')
            },
            showButtons: true
        };
    }
});
</script>

<template>
    <Head>
        <title>Claim Your Fursuit Badge and Join the Fun!</title>
        <meta head-key="description" name="description"
              content="Get your personalized fursuit badge at Eurofurence! Enjoy one free badge with registration, order extras for just 2 € each, and join our exciting Catch-Em-All game. Celebrate your fursuit and connect with fellow fursuiters."/>
    </Head>
    <div class="mb-6 lg:pt-8">
        <div
            class="lg:rounded-lg lg:drop-shadow lg:px-6 bannerImage md:min-h-[508px] flex flex-col items-center px-6 py-12 md:py-4 space-y-8">
            <div class="text-white flex-1 text-center flex flex-col justify-center items-center">
                <h1 class="font-main text-2xl md:text-5xl font-semibold drop-shadow-lg mb-4">{{ messages.hero_title }}</h1>
                <p class="max-w-screen-sm text-lg">{{ messages.hero_subtitle }}</p>
            </div>
            <div class="flex flex-col md:flex-row gap-3 text-white w-full" v-if="messages.showButtons">
                <!-- Action Select -->
                <Button icon="pi pi-id-card" class="w-full" label="Claim your first free Fursuit Badge!"/>
                <!-- Buy Additional Fursuit Badges -->
                <Button icon="pi pi-id-card" severity="warning" class="w-full"
                        label="Buy Additional Fursuit Badges"/>
            </div>
        </div>
    </div>
    <div class="px-6 xl:px-0">
        <!-- Countdown for End of Preorder -->
        <Message
            v-if="messages.message"
            severity="info"
                 :closable="false">Please note that you can claim your free fursuit badge only until {{ dayjs() }}
        </Message>
        <!-- End Countdown -->
        <h1 class="text-2xl font-semibold font-main">The Eurofurence Fursuit Badge</h1>
        <p class="mb-4">At Eurofurence, over 40% of our attendees are fursuiters, making us one of the top furry conventions with the highest number of costumers. Seeing so many furry critters roaming around is incredibly heartwarming, and we want to thank each and every one of you for returning and making the experience magical for everyone.</p>
        <p class="mb-4">Eurofurence offers every registered fursuiter one free personal fursuit badge. Bringing more than one fursuit? No problem! You can get a badge for each of them for just 2 € per additional badge. These badges are a fantastic way to show off your fursuit and make it easier for others to recognize you.</p>
        <p class="mb-4">Plus, you can join in on the fun with our
            <strong>Catch-Em-All</strong> Game! Meet fellow fursuiters, exchange badge codes, and see how many you can collect. The top collector will be celebrated at the closing ceremony and earn a spot on our eternal leaderboard.
        </p>
        <div class="mt-4">
            <h1 class="text-2xl font-semibold font-main">How to Get Your Badge:</h1>
            <ul class="list-disc pl-6 mt-2">
                <li class="mt-2">
                    <strong>First Preoder Badge Free:</strong> Simply register for Eurofurence and get your first fursuit badge for free.
                </li>
                <li class="mt-2">
                    <strong>Additional Badges & Late Orders:</strong> Order more for just 2 € each.
                </li>
                <li class="mt-2">
                    <strong>On-Site Services:</strong> Need a last-minute badge? We’ve got you covered with our late printing service for a small fee.
                </li>
            </ul>
        </div>

    </div>
</template>

<style>
.bannerImage {
    background-color: #f3f4f6;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    background-image: linear-gradient(
        rgba(20, 20, 20, 0.75),
        rgba(20, 20, 20, 0.75)
    ), url("../../assets/images/banner-mobile.jpg");
}

@media (min-width: 405px) {
    .bannerImage {
        background-image: linear-gradient(
            rgba(20, 20, 20, 0.75),
            rgba(20, 20, 20, 0.75)
        ), url("../../assets/images/banner-desktop.jpg");
    }
}
</style>
