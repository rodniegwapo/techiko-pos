/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

import axios from "axios";
window.axios = axios;

window.axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";

// CSRF token expired (e.g. the POS sat idle, or another tab rotated it): fetch a fresh token and try
// once more. If it still fails the session itself is gone, so reload and let the server redirect to
// login. The rejected error is marked so callers can skip their own "failed" message.
window.axios.interceptors.response.use(
    (response) => response,
    async (error) => {
        const config = error.config;
        if (error.response?.status !== 419) {
            return Promise.reject(error);
        }
        if (config && !config._csrfRetried) {
            config._csrfRetried = true;
            try {
                await window.axios.get("/sanctum/csrf-cookie");
                // A token copied from the page's meta tag is the stale one; the fresh cookie is sent instead.
                config.headers?.delete?.("X-CSRF-TOKEN");
                return await window.axios.request(config);
            } catch (retryError) {
                error = retryError;
                // 401: the fresh token came with a fresh, signed-out session.
                if (![401, 419].includes(retryError.response?.status)) {
                    return Promise.reject(retryError);
                }
            }
        }
        error.sessionExpired = true;
        window.location.reload();
        return Promise.reject(error);
    }
);

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allows your team to easily build robust real-time web applications.
 */

import Echo from "laravel-echo";

import Pusher from "pusher-js";
window.Pusher = Pusher;

// window.Echo = new Echo({
//     broadcaster: 'pusher',
//     key: import.meta.env.VITE_PUSHER_APP_KEY,
//     cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
//     wsHost: import.meta.env.VITE_PUSHER_HOST ? import.meta.env.VITE_PUSHER_HOST : `ws-${import.meta.env.VITE_PUSHER_APP_CLUSTER}.pusher.com`,
//     wsPort: import.meta.env.VITE_PUSHER_PORT ?? 80,
//     wssPort: import.meta.env.VITE_PUSHER_PORT ?? 443,
//     forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? 'https') === 'https',
//     enabledTransports: ['ws', 'wss'],
// });

window.Echo = new Echo({
    broadcaster: "pusher",
    key: import.meta.env.VITE_PUSHER_APP_KEY || process.env.MIX_PUSHER_APP_KEY,
    cluster:
        import.meta.env.VITE_PUSHER_APP_CLUSTER ||
        process.env.MIX_PUSHER_APP_CLUSTER,
    forceTLS: true,
});
