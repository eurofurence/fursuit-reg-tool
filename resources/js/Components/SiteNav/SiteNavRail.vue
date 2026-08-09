<script setup>
import { Link } from '@inertiajs/vue3';
import { useSiteNav } from './useSiteNav';

// The desktop counterpart of the bottom tab bar. Everything primary fits here, so the
// rail shows the full list rather than the tab bar's four-plus-More split.
//
// The "Open" marker lives here and not in the tab bar on purpose: the tab bar has ~64px
// per slot and already truncates its labels, so a second element would push the label out
// rather than add information.
const { primary, deskOpenNow } = useSiteNav();
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
                <span
                    v-if="item.key === 'pickup' && deskOpenNow"
                    class="flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                    :class="item.active ? 'bg-white/20 text-white' : 'bg-green-100 text-green-700'"
                >
                    <span
                        class="h-1.5 w-1.5 rounded-full"
                        :class="item.active ? 'bg-white' : 'bg-green-500'"
                    ></span>
                    Open
                </span>
            </Link>
        </div>
    </nav>
</template>
