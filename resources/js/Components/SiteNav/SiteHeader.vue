<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { LogOut, User } from 'lucide-vue-next';

const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);

const initials = computed(() => {
    const name = user.value?.name?.trim();
    if (!name) return '';

    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
});

const open = ref(false);
const menu = ref(null);

// The chip is the only header control, so a stray click anywhere else should close it
// rather than leave a panel floating over the page on the next navigation.
function closeOnOutside(event) {
    if (open.value && menu.value && !menu.value.contains(event.target)) {
        open.value = false;
    }
}

function closeOnEscape(event) {
    if (event.key === 'Escape') open.value = false;
}

onMounted(() => {
    document.addEventListener('click', closeOnOutside);
    document.addEventListener('keydown', closeOnEscape);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeOnOutside);
    document.removeEventListener('keydown', closeOnEscape);
});
</script>

<template>
    <header class="bg-primary-500 text-white relative z-30 shadow">
        <div class="site-container h-14 flex items-center gap-3">
            <Link :href="route('welcome')" class="flex items-center gap-2.5 min-w-0">
                <img src="../../../assets/ef.svg" class="h-8 w-8 shrink-0" alt="Eurofurence">
                <span class="font-logo tracking-wider font-semibold text-xl leading-none">EUROFURENCE</span>
                <span class="hidden md:inline text-sm text-white/70 truncate">Fursuit Badge Registration</span>
            </Link>

            <div ref="menu" class="ml-auto relative">
                <button
                    v-if="user"
                    type="button"
                    class="flex items-center gap-2 rounded-full bg-white/15 hover:bg-white/25 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white py-1 pl-1 pr-3 text-sm font-semibold transition-colors"
                    :aria-expanded="open"
                    aria-haspopup="menu"
                    @click="open = !open"
                >
                    <span class="grid place-items-center h-7 w-7 rounded-full bg-white text-primary-500 text-xs">
                        {{ initials || '?' }}
                    </span>
                    <span class="hidden sm:inline max-w-[12ch] truncate">{{ user.name }}</span>
                </button>

                <Link
                    v-else
                    :href="route('auth.login')"
                    class="flex items-center gap-2 rounded-full bg-white/15 hover:bg-white/25 py-1.5 px-3.5 text-sm font-semibold transition-colors"
                >
                    <User class="h-4 w-4"/>
                    Sign in
                </Link>

                <div
                    v-if="open && user"
                    role="menu"
                    class="absolute right-0 mt-2 w-56 rounded-lg bg-white text-gray-800 shadow-lg ring-1 ring-black/5 overflow-hidden"
                >
                    <div class="px-4 py-3 border-b border-gray-100">
                        <div class="font-semibold truncate">{{ user.name }}</div>
                        <div class="text-xs text-gray-500">Signed in</div>
                    </div>
                    <Link
                        :href="route('auth.logout')"
                        method="POST"
                        as="button"
                        class="w-full flex items-center gap-2 px-4 py-3 text-sm text-left hover:bg-gray-50"
                        role="menuitem"
                    >
                        <LogOut class="h-4 w-4"/>
                        Sign out
                    </Link>
                </div>
            </div>
        </div>
    </header>
</template>
