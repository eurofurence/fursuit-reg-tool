<script setup>
import { Link } from '@inertiajs/vue3';
import { useSiteNav } from './useSiteNav';

// The desktop counterpart of the bottom tab bar. Everything primary fits here, so the
// rail shows the full list rather than the tab bar's four-plus-More split.
const { primary } = useSiteNav();
</script>

<template>
    <nav class="hidden md:block bg-white border-b border-gray-200 relative z-20" aria-label="Main">
        <div class="site-container py-2 flex gap-1.5">
            <Link
                v-for="item in primary"
                :key="item.key"
                :href="item.href"
                class="flex items-center gap-2 rounded-full px-3.5 py-1.5 text-sm font-semibold transition-colors"
                :class="item.active
                    ? 'bg-primary-500 text-white'
                    : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'"
                :aria-current="item.active ? 'page' : undefined"
            >
                <component :is="item.icon" class="h-4 w-4"/>
                {{ item.label }}
            </Link>
        </div>
    </nav>
</template>
