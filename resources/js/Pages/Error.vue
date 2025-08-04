<script setup>
import { Head } from "@inertiajs/vue3";
import Button from 'primevue/button';
import Card from 'primevue/card';
import { Link } from "@inertiajs/vue3";
import Layout from "@/Layouts/Layout.vue";

defineOptions({
    layout: Layout
})

const props = defineProps({
    status: Number,
    message: String
});

const errorInfo = {
    404: {
        title: 'Page Not Found',
        description: 'The page you are looking for does not exist.',
        icon: 'pi pi-search',
        color: 'text-blue-500'
    },
    403: {
        title: 'Access Forbidden',
        description: 'You do not have permission to access this resource.',
        icon: 'pi pi-lock',
        color: 'text-red-500'
    },
    419: {
        title: 'Session Expired',
        description: 'Your session has expired. Please refresh the page and try again.',
        icon: 'pi pi-clock',
        color: 'text-yellow-500'
    },
    429: {
        title: 'Too Many Requests',
        description: 'You have made too many requests. Please try again later.',
        icon: 'pi pi-exclamation-triangle',
        color: 'text-orange-500'
    },
    500: {
        title: 'Server Error',
        description: 'Something went wrong on our end. Please try again later.',
        icon: 'pi pi-server',
        color: 'text-red-500'
    },
    503: {
        title: 'Service Unavailable',
        description: 'The service is temporarily unavailable. Please try again later.',
        icon: 'pi pi-wrench',
        color: 'text-gray-500'
    }
};

const currentError = errorInfo[props.status] || {
    title: 'Error',
    description: props.message || 'An unexpected error occurred.',
    icon: 'pi pi-exclamation-circle',
    color: 'text-red-500'
};
</script>

<template>
    <Head>
        <title>{{ currentError.title }} - Fursuit Badge System</title>
        <meta head-key="description" name="description" :content="currentError.description" />
    </Head>

    <!-- Hero Section -->
    <div class="relative z-0 mb-8">
        <div class="bannerImage flex flex-col items-center justify-center px-6 py-32 text-white text-center">
            <div class="flex flex-col">
                <div class="mb-6">
                    <i :class="[currentError.icon, currentError.color]" class="text-6xl drop-shadow-xl"></i>
                </div>
                <h1 class="font-main text-4xl md:text-6xl font-bold drop-shadow-xl mb-4">
                    {{ props.status }}
                </h1>
                <h2 class="text-2xl drop-shadow-lg max-w-3xl mx-auto leading-relaxed mb-4">
                    {{ currentError.title }}
                </h2>
                <p class="text-lg drop-shadow-lg max-w-3xl mx-auto leading-relaxed opacity-90">
                    {{ currentError.description }}
                </p>

                <!-- Action Buttons -->
                <div class="w-full max-w-xl mx-auto mt-8">
                    <div class="flex flex-col sm:flex-row gap-3">
                        <Link :href="route('welcome')" class="flex-1">
                            <Button 
                                icon="pi pi-home"
                                class="w-full text-xl font-bold shadow-2xl transform hover:scale-105 transition-all duration-200 bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 border-0 text-white"
                                size="large"
                                label="Go Home"
                            />
                        </Link>
                        <Button 
                            @click="window.history.back()"
                            icon="pi pi-arrow-left"
                            class="flex-1 font-semibold"
                            size="large"
                            label="Go Back"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="px-6 xl:px-0 max-w-6xl mx-auto pt-3">
            <!-- Error Details Card -->
            <div class="flex justify-center mb-8">
                <Card class="w-full max-w-2xl">
                    <template #title>
                        <div class="flex items-center gap-3">
                            <i :class="[currentError.icon, currentError.color]" class="text-3xl"></i>
                            <h2 class="text-2xl font-bold font-main">{{ currentError.title }}</h2>
                        </div>
                    </template>
                    <template #content>
                        <div class="space-y-4">
                            <p>{{ currentError.description }}</p>
                            
                            <div v-if="props.status === 419" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                <div class="flex items-start gap-3">
                                    <i class="pi pi-info-circle text-yellow-600 mt-1"></i>
                                    <div>
                                        <h4 class="font-semibold text-yellow-800 mb-2">What happened?</h4>
                                        <p class="text-yellow-700 text-sm">
                                            Your session has expired for security reasons. This usually happens when you've been inactive for a while or if you have multiple tabs open.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div v-if="props.status === 429" class="bg-orange-50 border border-orange-200 rounded-lg p-4">
                                <div class="flex items-start gap-3">
                                    <i class="pi pi-info-circle text-orange-600 mt-1"></i>
                                    <div>
                                        <h4 class="font-semibold text-orange-800 mb-2">What happened?</h4>
                                        <p class="text-orange-700 text-sm">
                                            You've made too many requests in a short period. Please wait a moment before trying again.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div v-if="props.status >= 500" class="bg-red-50 border border-red-200 rounded-lg p-4">
                                <div class="flex items-start gap-3">
                                    <i class="pi pi-info-circle text-red-600 mt-1"></i>
                                    <div>
                                        <h4 class="font-semibold text-red-800 mb-2">What happened?</h4>
                                        <p class="text-red-700 text-sm">
                                            We're experiencing technical difficulties. Our team has been notified and is working to resolve the issue. Please try again in a few minutes.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col sm:flex-row gap-3 pt-4">
                                <Link :href="route('welcome')" class="flex-1">
                                    <Button 
                                        icon="pi pi-home"
                                        class="w-full"
                                        size="large"
                                        label="Return to Home"
                                    />
                                </Link>
                                <Button 
                                    v-if="props.status === 419"
                                    @click="window.location.reload()"
                                    icon="pi pi-refresh"
                                    class="flex-1"
                                    size="large"
                                    label="Refresh Page"
                                />
                            </div>
                        </div>
                    </template>
                </Card>
            </div>
        </div>
    </div>
</template>

<style>
.bannerImage {
    background-color: #f3f4f6;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    background-image: linear-gradient(rgba(20, 20, 20, 0.75),
            rgba(20, 20, 20, 0.75)), url("../../assets/images/banner-mobile.jpg");
}

@media (min-width: 405px) {
    .bannerImage {
        background-image: linear-gradient(rgba(20, 20, 20, 0.75),
                rgba(20, 20, 20, 0.75)), url("../../assets/images/banner-desktop.jpg");
    }
}
</style>