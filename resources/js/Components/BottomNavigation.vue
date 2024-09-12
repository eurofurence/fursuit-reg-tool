<script setup>
    const items = [
        { text: 'Leaderboard', icon: 'pi-list' },
        { text: 'Catch!', icon: 'pi-qrcode' },
    ]
</script>

<template>
    <div class="shadow-lg shadow-black drop-shadow-lg bg-white flex flex-row">
        <div v-for="i in items" v-ripple class="hover:bg-primary-highlight-hover flex-1 cursor-pointer px-4 py-4 text-center flex items-center flex-col gap-2">
            <span class="pi" :class="i.icon"></span>
            <span>{{ i.text }}</span>
        </div>
    </div>
</template>
