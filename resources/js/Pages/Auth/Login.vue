<script setup>
import {Link,Head} from '@inertiajs/vue3'
</script>

<template>
    <Head title="Login" />
    <div class="flex flex-col justify-between min-h-screen py-4 px-6">
        <div class="mb-4">
            <h1 class="text-2xl font-semibold">Seat Reservation System</h1>
            <p class="text-gray-700 text-sm">You can use this system to reserve seats for the main Eurofurence Events.</p>
        </div>
        <!-- Tailwind UI Login Button -->
        <div>
            <Link as="button" method="post" :href="route('auth.login.redirect')" class="bg-blue-500 block text-center hover:bg-blue-700 text-white font-bold py-2 px-4 rounded w-full">
                Login
            </Link>
        </div>
    </div>
</template>

<style scoped>

</style>
