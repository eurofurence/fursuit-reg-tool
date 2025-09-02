<script setup>
import Button from "primevue/button";
import Menu from "primevue/menu";
import { Link, router } from "@inertiajs/vue3";
import DigitalClock from "@/Components/POS/DigitalClock.vue";
import Badge from "primevue/badge";
import { ref, computed, onMounted, onUnmounted, nextTick } from "vue";
import { usePosKeyboard } from '@/composables/usePosKeyboard';

// QZ Status management
const qzStatus = ref({
    qz_status: 'disconnected',
    is_connected: false,
    pending_jobs: 0,
    last_seen: null
});

// Printer states management
const printerStates = ref({});

// Handle QZ status updates from QZPrintService
const handleQzStatusChange = (status) => {
    qzStatus.value = { ...qzStatus.value, ...status };
};

const handlePendingJobsUpdate = (count) => {
    qzStatus.value.pending_jobs = count;
};

const handlePrinterStatesUpdate = (states) => {
    console.log('🎯 POSLayout received printer states update:', states);
    printerStates.value = states;
    console.log('📊 POSLayout printer states updated to:', printerStates.value);
};
import QZPrintService from "@/Components/POS/QZPrintService.vue";
import ToastService from "@/Components/POS/ToastService.vue";
import ShortcutsDialog from "@/Components/POS/ShortcutsDialog.vue";
import QzStatusIndicator from "@/Components/POS/QzStatusIndicator.vue";
import PrinterStatusIndicator from "@/Components/POS/PrinterStatusIndicator.vue";
import InactivityTimer from "@/Components/POS/InactivityTimer.vue";
import AutoLogoutModal from "@/Components/POS/AutoLogoutModal.vue";
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

const userMenu = ref();
const showShortcutsDialog = ref(false);
const showAutoLogoutModal = ref(false);

// Auto logout modal is now handled by the modal component

// Build the user menu
const userMenuItems = ref([
    { label: 'Switch User', icon: 'pi pi-user', route: route('pos.auth.user.logout'), method: 'POST' },
    { label: 'Keyboard Shortcuts', icon: 'pi pi-keyboard', command: () => showShortcutsDialog.value = true },
    { label: 'Auto Logout Settings', icon: 'pi pi-clock', command: () => showAutoLogoutModal.value = true },
]);

// Computed properties for printer status
const printerStatusSummary = computed(() => {
    const states = Object.values(printerStates.value);
    return {
        total: states.length,
        idle: states.filter(s => s.status === 'idle').length,
        working: states.filter(s => s.status === 'working').length,
        paused: states.filter(s => s.status === 'paused').length
    };
});

const hasPausedPrinters = computed(() => printerStatusSummary.value.paused > 0);

// Use the centralized keyboard handler composable
usePosKeyboard({
    // Global shortcuts are handled by the composable
    // Additional F1 handling for shortcuts dialog
});

// Additional keyboard shortcuts specific to layout
function handleLayoutShortcuts(e) {
    // F1: Show Shortcuts Dialog
    if (e.key === 'F1') {
        e.preventDefault();
        showShortcutsDialog.value = true;
    }
}

onMounted(() => {
    checkBreakpoint();
    window.addEventListener('resize', checkBreakpoint);
    window.addEventListener('keydown', handleLayoutShortcuts);
});

onUnmounted(() => {
    window.removeEventListener('resize', checkBreakpoint);
    window.removeEventListener('keydown', handleLayoutShortcuts);
});

const toggleUserMenu = (event) => {
    userMenu.value.toggle(event);
};

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
    <!-- Only load QZPrintService for devices that should discover printers -->
    <QZPrintService
        v-if="machine?.should_discover_printers"
        @qz-status-changed="handleQzStatusChange"
        @pending-jobs-updated="handlePendingJobsUpdate"
        @printer-states-updated="handlePrinterStatesUpdate"
    />
    <div class="min-h-screen w-full flex flex-col bg-gradient-to-br from-slate-50 to-slate-100">
        <!-- Compact System Bar -->
        <header class="bg-white border-b border-slate-200 h-8" v-if="page.props.auth.user">
            <div class="px-2 h-full flex items-center justify-between text-xs">
                <!-- Left: Back & Attendee -->
                <div class="flex items-center space-x-2">
                    <Link :href="route(backRoute)" v-if="shouldShowBackArrow" title="Back to Dashboard">
                        <i class="pi pi-arrow-left text-slate-600 hover:text-slate-800 cursor-pointer transition-colors"></i>
                    </Link>
                    <span v-if="attendee" class="text-slate-700 font-medium">
                        {{ attendee.name }} #{{ eventUser?.attendee_id || 'N/A' }}
                    </span>
                </div>

                <!-- Center: Clock | QZ Status | Printers -->
                <div class="flex items-center space-x-1 text-slate-600">
                    <DigitalClock class="font-medium"/>
                    <span class="text-slate-400">|</span>
                    <QzStatusIndicator v-if="qzStatus" :qz-status="qzStatus" :show-pending-jobs="false" />
                    <span v-else class="text-xs font-medium">POS Endpoint</span>
                    <span v-if="machine?.should_discover_printers" class="text-slate-400">|</span>
                    <Link v-if="machine?.should_discover_printers" :href="route('pos.printers.index')" class="flex items-center hover:text-slate-800">
                        <i class="pi pi-print mr-1" :class="hasPausedPrinters ? 'text-red-500' : ''"></i>
                        <span v-if="hasPausedPrinters" class="text-red-500 font-medium">{{ printerStatusSummary.paused }}</span>
                    </Link>
                </div>

                <!-- Right: Machine | Cashier | Card Reader | Printers | Menu -->
                <div class="flex items-center space-x-1 text-slate-600">
                    <i class="pi pi-desktop text-xs"></i>
                    <span class="font-medium">{{ machine?.name || 'Unknown' }}</span>
                    <span class="text-slate-400">|</span>
                    <i class="pi pi-user text-xs"></i>
                    <span class="font-medium">{{ cashier?.name || 'Unknown' }}</span>
                    <template v-if="machine?.sumup_reader">
                        <span class="text-slate-400">|</span>
                        <i class="pi pi-credit-card text-xs"></i>
                        <span class="font-medium">{{ machine.sumup_reader.name || 'Unknown Reader' }}</span>
                    </template>
                    <span class="text-slate-400">|</span>
                    <PrinterStatusIndicator />
                    <span class="text-slate-400">|</span>
                    <i class="pi pi-bars cursor-pointer hover:text-slate-800" @click="toggleUserMenu"></i>
                    <Menu ref="userMenu" id="overlay_menu" :model="userMenuItems" :popup="true" class="mt-2">
                        <template #item="{ item }">
                            <Link v-if="item.route" :href="item.route" :method="item.method" class="w-full">
                                <div class="flex items-center px-4 py-3 hover:bg-slate-50 transition-colors">
                                    <span :class="item.icon" class="text-slate-600"></span>
                                    <span class="ml-3 font-medium">{{ item.label }}</span>
                                </div>
                            </Link>
                            <a v-else @click="item.command" class="flex items-center px-4 py-3 hover:bg-slate-50 transition-colors cursor-pointer">
                                <span :class="item.icon" class="text-slate-600"></span>
                                <span class="ml-3 font-medium">{{ item.label }}</span>
                            </a>
                        </template>
                    </Menu>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex flex-1 p-2">
            <slot></slot>
            <ShortcutsDialog v-model:visible="showShortcutsDialog" />
            <AutoLogoutModal v-model:visible="showAutoLogoutModal" />
        </main>
    </div>
</template>
<style scoped>
/* Modern POS Layout Styles */
.pos-container {
    min-height: 100vh;
    font-size: 1rem;
}

/* Responsive text sizing for touch devices */
@media (max-width: 768px) {
    :deep(.p-button) {
        font-size: 1.1rem;
        min-height: 3rem;
        min-width: 3rem;
    }
}

/* Large touch targets for tablets */
@media (min-width: 768px) and (max-width: 1024px) {
    :deep(.p-button) {
        font-size: 1.2rem;
        min-height: 3.5rem;
        padding: 0.75rem 1.5rem;
    }
}

/* Desktop optimization */
@media (min-width: 1024px) {
    :deep(.p-button) {
        font-size: 1rem;
        min-height: 2.75rem;
    }
}
</style>
