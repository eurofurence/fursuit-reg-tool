<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { legalLinks } from './navItems';
import { useSiteNav } from './useSiteNav';

// Deliberately small. The tab bar and the rail do the navigating, so the footer is a
// safety net for people who scroll to the bottom looking for one, plus the legal links.
const { primary, secondary } = useSiteNav();

const links = computed(() => [...primary.value, ...secondary.value]);
</script>

<template>
    <footer class="site-container text-primary-950 text-center pt-8 pb-4">
        <!-- Hidden below md: the bottom tab bar carries the same destinations, and on a
             phone the two rows of links ended up stacked directly on top of each other. -->
        <div class="hidden md:flex flex-wrap justify-center gap-2 mb-3">
            <component
                :is="item.external ? 'a' : Link"
                v-for="item in links"
                :key="item.key"
                :href="item.href"
                :target="item.external ? '_blank' : undefined"
                :rel="item.external ? 'noopener' : undefined"
                class="bg-white border border-gray-200 rounded-full px-3.5 py-1.5 text-sm font-semibold hover:border-primary-500 hover:text-primary-500 transition-colors"
            >
                {{ item.label }}
            </component>
        </div>

        <p class="text-xs text-gray-500">&copy; {{ new Date().getFullYear() }} Eurofurence e.V. All rights reserved.</p>
        <p class="text-xs text-gray-500 mt-0.5">
            <template v-for="(link, index) in legalLinks" :key="link.key">
                <span v-if="index > 0" class="mx-2">|</span>
                <a :href="link.href" target="_blank" rel="noopener" class="underline">{{ link.label }}</a>
            </template>
        </p>
    </footer>
</template>
