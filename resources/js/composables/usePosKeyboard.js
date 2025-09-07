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

    function isOnAttendeeLookupPage() {
        return getCurrentRoute().includes('/pos/attendees/lookup');
    }

    function isOnPinLoginPage() {
        // The PIN login page is actually at /pos/auth/login
        return getCurrentRoute().includes('/pos/auth/login');
    }

    function handleKeydown(event) {
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
                // Default behavior: Navigate to attendee search 
                // (unless on checkout page, lookup page, or PIN login page)
                if (!isOnCheckoutPage() && !isOnAttendeeLookupPage() && !isOnPinLoginPage()) {
                    router.visit('/pos/attendees/lookup');
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
                router.visit('/pos/attendees/lookup');
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
            
            // Enter: Confirm Dialogs
            if (event.key === 'Enter') {
                window.dispatchEvent(new CustomEvent('pos-shortcut-confirm'));
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
        isOnAttendeeLookupPage,
        getCurrentRoute
    };
}