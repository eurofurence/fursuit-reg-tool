<script setup>
import { computed, onBeforeUnmount, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { LogOut, User, X } from 'lucide-vue-next';
import { legalLinks } from './navItems';
import { useSiteNav } from './useSiteNav';

const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const page = usePage();
const { overflow, isAuthenticated } = useSiteNav();

const user = computed(() => page.props.auth?.user ?? null);

// A bottom sheet over a scrollable page: without this the page keeps scrolling under
// the sheet on iOS and the sheet appears to drift.
watch(
    () => props.open,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : '';
    }
);

onBeforeUnmount(() => {
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-150"
            leave-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="md:hidden fixed inset-0 z-50 bg-black/40" @click="emit('close')"></div>
        </Transition>

        <Transition
            enter-active-class="transition-transform duration-200 ease-out"
            leave-active-class="transition-transform duration-150 ease-in"
            enter-from-class="translate-y-full"
            leave-to-class="translate-y-full"
        >
            <div
                v-if="open"
                class="md:hidden fixed inset-x-0 bottom-0 z-50 bg-white rounded-t-2xl shadow-2xl max-h-[80vh] overflow-y-auto"
                role="dialog"
                aria-modal="true"
                aria-label="More"
            >
                <div class="flex items-center justify-between px-5 pt-4 pb-2">
                    <h2 class="font-semibold text-lg">More</h2>
                    <button
                        type="button"
                        class="grid place-items-center h-9 w-9 rounded-full text-gray-500 hover:bg-gray-100"
                        aria-label="Close"
                        @click="emit('close')"
                    >
                        <X class="h-5 w-5"/>
                    </button>
                </div>

                <nav v-if="overflow.length" class="px-3 pb-2">
                    <component
                        :is="item.external ? 'a' : Link"
                        v-for="item in overflow"
                        :key="item.key"
                        :href="item.href"
                        :target="item.external ? '_blank' : undefined"
                        :rel="item.external ? 'noopener' : undefined"
                        class="flex items-center gap-3 rounded-xl px-3 py-3 text-[15px] font-semibold"
                        :class="item.active ? 'bg-primary-500/10 text-primary-500' : 'text-gray-800 hover:bg-gray-50'"
                        :aria-current="item.active ? 'page' : undefined"
                        @click="emit('close')"
                    >
                        <component :is="item.icon" class="h-5 w-5 text-gray-500"/>
                        {{ item.label }}
                    </component>
                </nav>

                <div class="border-t border-gray-100 px-3 py-2">
                    <Link
                        v-if="isAuthenticated"
                        :href="route('auth.logout')"
                        method="POST"
                        as="button"
                        class="w-full flex items-center gap-3 rounded-xl px-3 py-3 text-[15px] font-semibold text-gray-800 hover:bg-gray-50 text-left"
                        @click="emit('close')"
                    >
                        <LogOut class="h-5 w-5 text-gray-500"/>
                        Sign out{{ user?.name ? ` (${user.name})` : '' }}
                    </Link>
                    <Link
                        v-else
                        :href="route('auth.login')"
                        class="w-full flex items-center gap-3 rounded-xl px-3 py-3 text-[15px] font-semibold text-gray-800 hover:bg-gray-50"
                        @click="emit('close')"
                    >
                        <User class="h-5 w-5 text-gray-500"/>
                        Sign in
                    </Link>
                </div>

                <div
                    class="border-t border-gray-100 px-6 py-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500"
                    style="padding-bottom: calc(1rem + env(safe-area-inset-bottom))"
                >
                    <a
                        v-for="link in legalLinks"
                        :key="link.key"
                        :href="link.href"
                        target="_blank"
                        rel="noopener"
                        class="underline"
                    >{{ link.label }}</a>
                    <span class="ml-auto">&copy; {{ new Date().getFullYear() }} Eurofurence e.V.</span>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
