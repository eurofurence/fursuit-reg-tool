<script setup>
import { Link, router } from "@inertiajs/vue3";
import { ArrowLeft, Clock, CreditCard, Keyboard, Menu as MenuIcon, Monitor, User } from "@lucide/vue";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/Components/UI/dropdown-menu";
import DigitalClock from "@/Components/POS/DigitalClock.vue";
import { ref, computed, onMounted, onUnmounted, nextTick } from "vue";
import { usePosKeyboard } from '@/composables/usePosKeyboard';

import ToastService from "@/Components/POS/ToastService.vue";
import ShortcutsDialog from "@/Components/POS/ShortcutsDialog.vue";
import PrinterStatusIndicator from "@/Components/POS/PrinterStatusIndicator.vue";
import InactivityTimer from "@/Components/POS/InactivityTimer.vue";
import AutoLogoutModal from "@/Components/POS/AutoLogoutModal.vue";
import SumUpReaderModal from "@/Components/POS/SumUpReaderModal.vue";
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const cashier = computed(() => page.props.auth.user);
const machine = computed(() => page.props.auth.machine);
const printerStatus = computed(() => page.props.printerStatus);

// Responsive breakpoint detection
const isMobile = ref(false);
const isTablet = ref(false);

const checkBreakpoint = () => {
    const width = window.innerWidth;
    isMobile.value = width < 768;
    isTablet.value = width >= 768 && width < 1024;
};

onMounted(() => {
    checkBreakpoint();
    window.addEventListener('resize', checkBreakpoint);
});

onUnmounted(() => {
    window.removeEventListener('resize', checkBreakpoint);
});

const showShortcutsDialog = ref(false);
const showAutoLogoutModal = ref(false);
const showSumUpReaderModal = ref(false);

// Auto logout modal is now handled by the modal component

const userMenuItems = [
    { label: 'Switch User', icon: User, command: () => router.post(route('pos.auth.user.logout')) },
    { label: 'Keyboard Shortcuts', icon: Keyboard, command: () => showShortcutsDialog.value = true },
    { label: 'Auto Logout Settings', icon: Clock, command: () => showAutoLogoutModal.value = true },
];

// Use the centralized keyboard handler composable
usePosKeyboard({
    // Global shortcuts are handled by the composable
    // Additional F1 handling for shortcuts dialog
});

// Backspace logout feature
const backspaceCount = ref(0);
const backspaceTimer = ref(null);

function resetBackspaceCount() {
    backspaceCount.value = 0;
    if (backspaceTimer.value) {
        clearTimeout(backspaceTimer.value);
        backspaceTimer.value = null;
    }
}

function handleBackspaceLogout(e) {
    // Only track backspace when not in an input field
    const isInputElement = (element) => {
        const tagName = element.tagName.toLowerCase();
        return tagName === 'input' || tagName === 'textarea' || tagName === 'select' || 
               element.contentEditable === 'true';
    };
    
    if (e.key === 'Backspace' && !isInputElement(e.target)) {
        e.preventDefault(); // Prevent browser back navigation
        
        backspaceCount.value++;
        console.log(`[POSLayout] Backspace pressed ${backspaceCount.value}/5 times`);
        
        // Reset count after 2 seconds of no backspace
        if (backspaceTimer.value) {
            clearTimeout(backspaceTimer.value);
        }
        backspaceTimer.value = setTimeout(resetBackspaceCount, 2000);
        
        // Logout after 5 backspaces
        if (backspaceCount.value >= 5) {
            console.log('[POSLayout] 5 backspaces detected - logging out');
            resetBackspaceCount();
            // Use the same logout method as InactivityTimer
            router.post(route('pos.auth.user.logout'));
        }
    } else if (e.key !== 'Backspace') {
        // Reset count if any other key is pressed
        resetBackspaceCount();
    }
}

// Additional keyboard shortcuts specific to layout
function handleLayoutShortcuts(e) {
    // F1: Show Shortcuts Dialog
    if (e.key === 'F1') {
        e.preventDefault();
        showShortcutsDialog.value = true;
    }
    
    // Handle backspace logout
    handleBackspaceLogout(e);
}

onMounted(() => {
    checkBreakpoint();
    window.addEventListener('resize', checkBreakpoint);
    window.addEventListener('keydown', handleLayoutShortcuts);
});

onUnmounted(() => {
    window.removeEventListener('resize', checkBreakpoint);
    window.removeEventListener('keydown', handleLayoutShortcuts);
    resetBackspaceCount(); // Clean up backspace timer
});

const props = defineProps({
    attendee: Object || undefined, // from backend
    eventUser: Object || undefined, // event-specific user data
    backToRoute: String || undefined
});

// Determine if we should show the back arrow
const shouldShowBackArrow = computed(() => {
    // If backToRoute is explicitly provided, use it
    if (props.backToRoute) {
        return true;
    }
    
    // Use the reactive page.url instead of route().current() for proper reactivity
    const currentUrl = page.url;
    
    // Don't show back arrow on dashboard, checkout pages, and auth pages
    if (currentUrl === route('pos.dashboard') || 
        currentUrl.startsWith(route('pos.checkout.index')) ||
        currentUrl.startsWith(route('pos.auth.user.select'))) {
        return false;
    }
    
    return true;
});

// Determine the back route (default to dashboard)
const backRoute = computed(() => {
    return props.backToRoute || 'pos.dashboard';
});
</script>

<template>
    <ToastService/>
    <InactivityTimer/>
    <div class="pos min-h-screen w-full flex flex-col">
        <!-- Compact System Bar -->
        <header class="bg-pos-panel-2 border-b border-pos-line h-9" v-if="page.props.auth.user">
            <div class="px-2 h-full flex items-center justify-between text-xs text-pos-muted">
                <!-- Left: Back & Attendee -->
                <div class="flex items-center space-x-2">
                    <Link :href="route(backRoute)" v-if="shouldShowBackArrow" title="Back to Dashboard"
                          class="flex items-center gap-1 hover:text-pos-text">
                        <ArrowLeft class="size-3.5" aria-hidden="true" />
                        <span class="pos-kcap">−</span>
                    </Link>
                    <span v-if="attendee" class="text-pos-text font-semibold">
                        {{ attendee.name }} <span class="pos-num">#{{ eventUser?.attendee_id || 'N/A' }}</span>
                    </span>
                </div>

                <!-- Center: Clock -->
                <div class="flex items-center space-x-1">
                    <DigitalClock class="font-medium pos-num text-pos-text"/>
                </div>

                <!-- Right: Machine | Cashier | Card Reader | Printers | Menu -->
                <div class="flex items-center space-x-1">
                    <Monitor class="size-3" aria-hidden="true" />
                    <span class="font-medium">{{ machine?.name || 'Unknown' }}</span>
                    <span class="text-pos-line-strong">|</span>
                    <User class="size-3" aria-hidden="true" />
                    <span class="font-medium text-pos-text">{{ cashier?.name || 'Unknown' }}</span>
                    <!--
                        Always shown, and always a button: a till with no reader
                        is the state a clerk most needs to fix from here, so
                        hiding the control exactly then was backwards.
                    -->
                    <span class="text-pos-line-strong">|</span>
                    <button
                        type="button"
                        class="flex items-center gap-1 hover:text-pos-text"
                        title="Choose the card reader for this till"
                        @click="showSumUpReaderModal = true"
                    >
                        <CreditCard class="size-3" aria-hidden="true" />
                        <span class="font-medium" :class="machine?.sumup_reader ? '' : 'text-pos-warn'">
                            {{ machine?.sumup_reader?.name || 'No reader' }}
                        </span>
                    </button>
                    <span class="text-pos-line-strong">|</span>
                    <PrinterStatusIndicator />
                    <span class="text-pos-line-strong">|</span>
                    <DropdownMenu :modal="false">
                        <DropdownMenuTrigger as-child>
                            <button type="button" class="flex items-center p-1 hover:text-pos-text" aria-label="Menu">
                                <MenuIcon class="size-3.5" aria-hidden="true" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="pos-surface min-w-56 p-0">
                            <DropdownMenuItem
                                v-for="item in userMenuItems"
                                :key="item.label"
                                class="min-h-pos-touch gap-3 rounded-none border-pos-line px-4 text-sm font-medium not-first:border-t"
                                @select="item.command"
                            >
                                <component :is="item.icon" class="text-pos-muted" aria-hidden="true" />
                                {{ item.label }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex flex-1 p-2">

            <slot></slot>
            <ShortcutsDialog v-model:visible="showShortcutsDialog" />
            <AutoLogoutModal v-model:visible="showAutoLogoutModal" />
            <SumUpReaderModal v-model:visible="showSumUpReaderModal" />
        </main>
    </div>
</template>
