<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();
const machine = computed(() => page.props.auth.machine);

// null is a real setting here — it means "Off" — so only an absent value falls
// back to 5 minutes.
const getTimeoutFromMachine = () => {
    const timeout = machine.value?.auto_logout_timeout;

    return timeout === undefined ? 300 : timeout;
};

// Reactive timeout configuration
const configuredTimeout = ref(getTimeoutFromMachine());
const WARNING_DURATION = 10; // Always 10 seconds of warning
const INACTIVITY_WARNING_TIME = computed(() => Math.max(configuredTimeout.value - WARNING_DURATION, 0));
const INACTIVITY_TIMEOUT = computed(() => configuredTimeout.value);

// State
const lastActivityTime = ref(Date.now());
const isWarning = ref(false);
const blurLevel = ref(0);
const timeUntilLogout = ref(null);
const currentRoute = ref(route().current());

// Activity tracking
const resetTimer = () => {
    lastActivityTime.value = Date.now();
    isWarning.value = false;
    blurLevel.value = 0;
    timeUntilLogout.value = null;
};

// Track user activity
const activityEvents = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];

const handleActivity = () => {
    // console.log('[InactivityTimer] Activity detected, resetting timer');
    resetTimer();

    // If warning was showing, cancel it and restart the timer
    if (isWarning.value) {
        console.log('[InactivityTimer] Dismissing warning due to activity');
        isWarning.value = false;
        blurLevel.value = 0;
        timeUntilLogout.value = null;

        // Clear and restart the warning interval
        if (warningInterval) {
            clearInterval(warningInterval);
            warningInterval = null;
        }
    }
};

// Check for route names that should have the timer active
const isTimerActive = computed(() => {
    // Timer is disabled if timeout is null (Off)
    if (configuredTimeout.value === null) {
        console.log('[InactivityTimer] Timer disabled - timeout set to Off');
        return false;
    }

    // Timer is active on all POS routes except auth routes, and except the
    // verification screen. Checking a crate off is minutes of handling cards
    // with no keyboard or mouse in between, and locking there throws away the
    // list of what was already checked - the one thing the clerk is reading
    // off the screen. That screen holds no attendee data and no till, so the
    // lock buys nothing there; it polls the server itself to hold the session.
    const isActive = currentRoute.value
        && currentRoute.value.startsWith('pos.')
        && !currentRoute.value.startsWith('pos.auth.')
        && !currentRoute.value.startsWith('pos.verification.');
    console.log('[InactivityTimer] Route check:', {
        currentRoute: currentRoute.value,
        isActive,
        timeout: configuredTimeout.value,
        warningTime: INACTIVITY_WARNING_TIME.value
    });
    return isActive;
});

// Lock screen function
const lockScreen = async () => {
    // Save current URL if it's a GET route
    const currentUrl = window.location.pathname + window.location.search;
    console.log('[InactivityTimer] Locking screen! Current URL:', currentUrl);

    // Call the lock endpoint
    try {
        await router.post(route('pos.auth.lock'), {
            return_url: currentUrl
        }, {
            preserveState: false,
            preserveScroll: false,
            onSuccess: () => {
                console.log('[InactivityTimer] Lock successful, redirecting to login');
                // The backend will redirect to login
            },
            onError: (error) => {
                console.error('[InactivityTimer] Lock failed:', error);
            }
        });
    } catch (error) {
        console.error('[InactivityTimer] Failed to lock screen:', error);
        // Fallback to regular logout
        console.log('[InactivityTimer] Falling back to regular logout');
        router.post(route('pos.auth.user.logout'));
    }
};

// Timer interval
let timerInterval = null;
let warningInterval = null;

const startTimer = () => {
    if (timerInterval) {
        console.log('[InactivityTimer] Timer already running');
        return;
    }

    console.log('[InactivityTimer] Starting inactivity timer', {
        warningTime: INACTIVITY_WARNING_TIME.value,
        timeout: INACTIVITY_TIMEOUT.value
    });

    timerInterval = setInterval(() => {
        if (!isTimerActive.value) {
            console.log('[InactivityTimer] Timer check skipped - not on POS route or disabled');
            return;
        }

        const elapsedSeconds = Math.floor((Date.now() - lastActivityTime.value) / 1000);

        if (elapsedSeconds >= INACTIVITY_TIMEOUT.value) {
            console.log('[InactivityTimer] TIMEOUT REACHED - locking screen!');
            // Time's up - lock the screen
            lockScreen();
        } else if (elapsedSeconds >= INACTIVITY_WARNING_TIME.value) {
            // Show warning
            if (!isWarning.value) {
                console.log('[InactivityTimer] WARNING TIME - showing blur effect');
                isWarning.value = true;
                startWarningEffect();
            }
            timeUntilLogout.value = INACTIVITY_TIMEOUT.value - elapsedSeconds;
            console.log('[InactivityTimer] Countdown:', timeUntilLogout.value);
        }
    }, 1000);
};

const startWarningEffect = () => {
    if (warningInterval) return;

    let progress = 0;
    warningInterval = setInterval(() => {
        progress += 100 / (WARNING_DURATION * 10); // Update every 100ms for smooth animation
        blurLevel.value = Math.min(progress / 100, 1); // 0 to 1 over WARNING_DURATION

        if (progress >= 100) {
            clearInterval(warningInterval);
            warningInterval = null;
        }
    }, 100);
};

const stopTimer = () => {
    if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
    }
    if (warningInterval) {
        clearInterval(warningInterval);
        warningInterval = null;
    }
};

// Setup and teardown functions
const setupTimer = () => {
    console.log('[InactivityTimer] Setting up timer and listeners');
    // Add activity listeners
    activityEvents.forEach(event => {
        document.addEventListener(event, handleActivity);
    });
    // Start the timer
    startTimer();
    resetTimer(); // Reset to start fresh
};

const teardownTimer = () => {
    console.log('[InactivityTimer] Tearing down timer and listeners');
    // Remove activity listeners
    activityEvents.forEach(event => {
        document.removeEventListener(event, handleActivity);
    });
    // Stop the timer
    stopTimer();
    // Clear any warning state
    isWarning.value = false;
    blurLevel.value = 0;
    timeUntilLogout.value = null;
};

// Inertia router event handlers
const handleRouteStart = (event) => {
    console.log('[InactivityTimer] Route start detected:', event.detail.visit.url);
};

const handleRouteFinish = () => {
    console.log('[InactivityTimer] Route finish detected');
    // Update to the actual current route after navigation completes
    const routeName = route().current();
    console.log('[InactivityTimer] Final route name:', routeName);
    currentRoute.value = routeName;
};

// Event listener cleanup functions
let removeStartListener = null;
let removeFinishListener = null;

// Watch for machine prop changes to update timeout
watch(() => machine.value?.auto_logout_timeout, (newTimeout) => {
    console.log('[InactivityTimer] Machine timeout changed to:', newTimeout);

    // Same rule as getTimeoutFromMachine: null means Off, not "use the default".
    configuredTimeout.value = newTimeout === undefined ? 300 : newTimeout;

    // Switching to Off has to stop a running timer, not just skip the restart.
    if (configuredTimeout.value === null) {
        stopTimer();
        resetTimer();

        return;
    }

    // If timer is currently active, restart it with new timeout
    if (isTimerActive.value) {
        console.log('[InactivityTimer] Restarting timer with new timeout');
        stopTimer();
        startTimer();
        resetTimer();
    }
}, { immediate: true });

// Lifecycle
onMounted(() => {
    console.log('[InactivityTimer] Component mounted, checking if timer should be active');
    console.log('[InactivityTimer] Current route:', currentRoute.value);
    console.log('[InactivityTimer] Timer active?', isTimerActive.value);

    // Set up Inertia router listeners and store cleanup functions
    console.log('[InactivityTimer] Setting up Inertia router listeners');
    removeStartListener = router.on('start', handleRouteStart);
    removeFinishListener = router.on('finish', handleRouteFinish);

    if (isTimerActive.value) {
        setupTimer();
    } else {
        console.log('[InactivityTimer] Timer not active on this route');
    }
});

onUnmounted(() => {
    console.log('[InactivityTimer] Component unmounting, cleaning up');

    // Remove Inertia router listeners using the cleanup functions
    if (removeStartListener) {
        console.log('[InactivityTimer] Removing start event listener');
        removeStartListener();
    }
    if (removeFinishListener) {
        console.log('[InactivityTimer] Removing finish event listener');
        removeFinishListener();
    }

    teardownTimer();
});

// Watch for route changes and dynamically enable/disable timer
watch(isTimerActive, (newValue, oldValue) => {
    console.log('[InactivityTimer] Timer active state changed:', { oldValue, newValue });

    if (newValue && !oldValue) {
        // Timer should be active but wasn't - set it up
        console.log('[InactivityTimer] Route changed to POS page - activating timer');
        setupTimer();
    } else if (!newValue && oldValue) {
        // Timer was active but shouldn't be - tear it down
        console.log('[InactivityTimer] Route changed to auth page - deactivating timer');
        teardownTimer();
    } else if (newValue) {
        // Timer remains active, just reset it
        console.log('[InactivityTimer] Route changed within POS pages - resetting timer');
        resetTimer();
    }
}, { immediate: false });

// Debug: Log component initialization
console.log('[InactivityTimer] Component loaded, configs:', {
    INACTIVITY_WARNING_TIME: INACTIVITY_WARNING_TIME.value,
    INACTIVITY_TIMEOUT: INACTIVITY_TIMEOUT.value,
    WARNING_DURATION,
    configuredTimeout: configuredTimeout.value
});

// Computed styles
const overlayStyle = computed(() => {
    if (!isWarning.value) return {};

    return {
        position: 'fixed',
        top: 0,
        left: 0,
        right: 0,
        bottom: 0,
        backgroundColor: `rgb(var(--pos-text) / ${blurLevel.value * 0.6})`,
        backdropFilter: `blur(${blurLevel.value * 20}px)`,
        WebkitBackdropFilter: `blur(${blurLevel.value * 20}px)`,
        zIndex: 9998,
        pointerEvents: 'none',
        transition: 'backdrop-filter 0.3s ease-out, background-color 0.3s ease-out'
    };
});

const warningMessageStyle = computed(() => {
    if (!isWarning.value) return { display: 'none' };

    return {
        position: 'fixed',
        top: '50%',
        left: '50%',
        transform: 'translate(-50%, -50%)',
        zIndex: 9999,
        backgroundColor: 'rgb(var(--pos-panel))',
        color: 'rgb(var(--pos-text))',
        padding: '2rem 3rem',
        border: '1px solid rgb(var(--pos-line-strong))',
        borderRadius: 'var(--pos-radius)',
        textAlign: 'center',
        minWidth: '300px',
        pointerEvents: 'auto',
        animation: 'pulse 1s infinite'
    };
});

// Format countdown
const formattedCountdown = computed(() => {
    if (!timeUntilLogout.value) return '';
    return `${timeUntilLogout.value}`;
});

// Handle continue button
const handleContinue = () => {
    resetTimer();
    stopTimer();
    startTimer();
};
</script>

<template>
    <!-- Blur overlay -->
    <div v-if="isWarning" :style="overlayStyle"></div>

    <!-- Warning message -->
    <div v-if="isWarning" :style="warningMessageStyle">
        <div class="flex flex-col items-center gap-3">
            <i class="pi pi-clock text-5xl text-pos-warn animate-pulse"></i>
            <h2 class="text-xl font-semibold text-pos-text">Session Timeout Warning</h2>
            <p class="pos-label">Your session expires in</p>
            <div class="pos-num text-5xl font-bold text-pos-bad leading-none">
                {{ formattedCountdown }}
            </div>
            <p class="text-sm text-pos-muted">seconds</p>
            <button
                type="button"
                @click="handleContinue"
                class="pos-btn pos-btn--primary pos-btn--commit w-full"
            >
                Continue Working
            </button>
        </div>
    </div>
</template>

<style scoped>
@keyframes pulse {
    0%, 100% {
        transform: translate(-50%, -50%) scale(1);
    }
    50% {
        transform: translate(-50%, -50%) scale(1.02);
    }
}
</style>
