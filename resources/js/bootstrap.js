import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 * 
 * Only load Echo for POS routes that need real-time functionality.
 */

// Only load Echo for POS routes
if (window.location.pathname.startsWith('/pos')) {
    import('./echo');
}
