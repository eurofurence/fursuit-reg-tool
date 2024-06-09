<script setup>
import {Link, Head} from '@inertiajs/vue3';
import Button from 'primevue/button';
</script>

<template>
    <Head title="Login"/>
    <div class="bg-gray-100 min-h-screen flex justify-between items-center">
        <div class=" w-full sm:max-w-md mx-auto bg-white rounded-lg shadow-lg py-4 px-6">
            <div class="mb-4">
                <h1 class="text-xl sm:text-2xl font-semibold">Eurofurence Fursuit Badges</h1>
                <p class="text-gray-700 text-sm">Claim your Fursuit Badge today!<br>Please login to continue.</p>
            </div>
            <!-- Tailwind UI Login Button -->
            <Link as="div" method="post" :href="route('auth.login.redirect')">
                <Button class="w-full" label="Login with Eurofurence Identity"/>
            </Link>
        </div>
    </div>
</template>

<style scoped>

</style>
