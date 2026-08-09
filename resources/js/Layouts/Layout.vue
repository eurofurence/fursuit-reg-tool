<script setup>
import SiteFooter from '@/Components/SiteNav/SiteFooter.vue';
import SiteHeader from '@/Components/SiteNav/SiteHeader.vue';
import SiteNavRail from '@/Components/SiteNav/SiteNavRail.vue';
import SiteTabBar from '@/Components/SiteNav/SiteTabBar.vue';
</script>

<template>
    <div class="bg-gray-100">
        <div class="min-h-screen w-full mx-auto flex flex-col">
            <SiteHeader/>
            <SiteNavRail/>

            <!-- pb-20 clears the fixed bottom tab bar, which only exists below md. -->
            <div class="pb-4 flex-1">
                <slot></slot>
            </div>

            <SiteFooter/>

            <div class="pb-20 md:pb-0"></div>
            <SiteTabBar/>

            <div id="bottom_nav_teleport"></div>
        </div>
    </div>
</template>
