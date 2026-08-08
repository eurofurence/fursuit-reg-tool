<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { MoreHorizontal } from 'lucide-vue-next';
import SiteMoreSheet from './SiteMoreSheet.vue';
import { useSiteNav } from './useSiteNav';

// Phone navigation lives at the bottom of the screen because that is where the thumb
// is. Four destinations plus "More"; see navItems.js for how the four are chosen.
const { tabs, overflow } = useSiteNav();

const moreOpen = ref(false);
</script>

<template>
    <nav
        class="md:hidden fixed inset-x-0 bottom-0 z-40 bg-white border-t border-gray-200 shadow-[0_-2px_10px_rgba(0,0,0,.06)] flex"
        style="padding-bottom: env(safe-area-inset-bottom)"
        aria-label="Main"
    >
        <Link
            v-for="item in tabs"
            :key="item.key"
            :href="item.href"
            class="flex-1 flex flex-col items-center gap-0.5 py-2 text-[11px] font-semibold"
            :class="item.active ? 'text-primary-500' : 'text-gray-500'"
            :aria-current="item.active ? 'page' : undefined"
        >
            <component :is="item.icon" class="h-6 w-6"/>
            {{ item.short }}
        </Link>

        <button
            type="button"
            class="flex-1 flex flex-col items-center gap-0.5 py-2 text-[11px] font-semibold"
            :class="moreOpen || overflow.some((item) => item.active) ? 'text-primary-500' : 'text-gray-500'"
            :aria-expanded="moreOpen"
            @click="moreOpen = true"
        >
            <MoreHorizontal class="h-6 w-6"/>
            More
        </button>
    </nav>

    <SiteMoreSheet :open="moreOpen" @close="moreOpen = false"/>
</template>
