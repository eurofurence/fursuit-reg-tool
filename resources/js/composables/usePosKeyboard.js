import { onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

export function usePosKeyboard(options = {}) {
    const {
        onNumpadDivide,
        onNumpadMultiply,
        onBackspace,
        disableGlobalShortcuts = false,
        excludeInputs = true
    } = options;

    const page = usePage();

    function isInputElement(element) {
        if (!excludeInputs) return false;
        const tagName = element.tagName.toLowerCase();
        return tagName === 'input' || tagName === 'textarea' || tagName === 'select' || element.contentEditable === 'true';
    }

    function getCurrentRoute() {
        return page.url;
    }

    function isOnCheckoutPage() {
        return getCurrentRoute().includes('/pos/checkout/');
    }

    function isOnAttendeeShowPage() {
        return getCurrentRoute().includes('/pos/attendees/show/');
    }

    function isOnDashboard() {
        return getCurrentRoute() === '/pos';
    }

    function isOnPinLoginPage() {
        // The PIN login page is actually at /pos/auth/login
        return getCurrentRoute().includes('/pos/auth/login');
    }

    // F-key navigation. Handled before the input-field guard on purpose: the
    // POS keeps a text field focused for the barcode scanner, and F-keys never
    // collide with typing.
    const FUNCTION_KEY_ROUTES = {
        F3: '/pos/badges',
        F4: '/pos/print-queue',
        F6: '/pos/statistics',
    };

    function handleKeydown(event) {
        if (! disableGlobalShortcuts && FUNCTION_KEY_ROUTES[event.key]) {
            event.preventDefault();
            router.visit(FUNCTION_KEY_ROUTES[event.key]);
            return;
        }

        // Don't handle shortcuts if user is typing in an input field
        if (isInputElement(event.target)) {
            return;
        }

        // Handle numpad divide (/) key
        if (event.code === 'NumpadDivide') {
            event.preventDefault();
            event.stopImmediatePropagation(); // Stop other handlers from firing
            
            if (onNumpadDivide) {
                onNumpadDivide(event);
                return; // Exit early when override is handled
            } else if (!disableGlobalShortcuts) {
                // Default behavior: jump to the dashboard, which is the search screen
                // (unless on checkout page, lookup page, or PIN login page)
                if (!isOnCheckoutPage() && !isOnDashboard() && !isOnPinLoginPage()) {
                    router.visit('/pos');
                }
            }
        }

        // Handle numpad multiply (*) key
        if (event.code === 'NumpadMultiply') {
            event.preventDefault();
            event.stopImmediatePropagation(); // Stop other handlers from firing
            
            if (onNumpadMultiply) {
                onNumpadMultiply(event);
                return; // Exit early when override is handled
            } else if (!disableGlobalShortcuts) {
                // Default behavior: Trigger handout
                window.dispatchEvent(new CustomEvent('pos-shortcut-handout'));
            }
        }

        // Handle Backspace key (for navigation)
        if (event.key === 'Backspace' && onBackspace) {
            // Only handle if not in an input field and callback is provided
            event.preventDefault();
            onBackspace(event);
        }

        // Keep existing Ctrl shortcuts working
        if (!disableGlobalShortcuts) {
            // Ctrl+K: Search Attendee
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                router.visit('/pos');
            }
            
            // Ctrl+P: Start Payment
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'p') {
                event.preventDefault();
                window.dispatchEvent(new CustomEvent('pos-shortcut-payment'));
            }
            
            // Ctrl+H: Handout All
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'h') {
                event.preventDefault();
                window.dispatchEvent(new CustomEvent('pos-shortcut-handout'));
            }
            
            // Enter: Confirm Dialogs.
            //
            // The event is cancelable; a page that confirms something calls
            // preventDefault() on it. We then swallow the keypress so it cannot
            // also activate whatever button regains focus behind the dialog.
            if (event.key === 'Enter') {
                const confirmEvent = new CustomEvent('pos-shortcut-confirm', { cancelable: true });
                const handled = ! window.dispatchEvent(confirmEvent);

                if (handled) {
                    event.preventDefault();
                }
            }
        }
    }

    onMounted(() => {
        // Use capture phase to ensure this handler runs first if it has overrides
        const useCapture = !!(onNumpadDivide || onNumpadMultiply || onBackspace);
        window.addEventListener('keydown', handleKeydown, useCapture);
    });

    onUnmounted(() => {
        const useCapture = !!(onNumpadDivide || onNumpadMultiply || onBackspace);
        window.removeEventListener('keydown', handleKeydown, useCapture);
    });

    return {
        isOnCheckoutPage,
        isOnAttendeeShowPage,
        isOnDashboard,
        getCurrentRoute
    };
}