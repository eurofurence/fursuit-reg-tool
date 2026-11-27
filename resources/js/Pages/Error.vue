<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Ban, CircleAlert, Clock, FileX, Hourglass, House, Lock, LogIn, RefreshCw, Search, Server, Trash2, TriangleAlert, Wrench } from '@lucide/vue';
import { Button } from '@/Components/UI/button';
import Layout from '@/Layouts/Layout.vue';

/*
 * The one error page for every interface.
 *
 * Rendered from the respond() callback in bootstrap/app.php, which decides the
 * status and hands over `context` so the page can pick a shell: the public site
 * keeps its header, rail and footer, while POS/admin/Catch-Em-All get a bare
 * centred card - their own layouts assume an authenticated session and a loaded
 * machine, neither of which is guaranteed when we are here.
 *
 * `bare` is its own context. It means the request never reached the web
 * middleware (a URI no route claimed), so there are no shared props at all -
 * SiteHeader would render against an undefined `event` and `auth`.
 *
 * The layout is chosen inside the template rather than through
 * defineOptions({ layout }) because that option is static, and this page has to
 * serve five interfaces.
 */
const props = defineProps({
    status: { type: Number, required: true },
    message: { type: String, default: null },
    context: { type: String, default: 'public' },
});

const catalog = {
    401: {
        title: 'Not Signed In',
        description: 'You need to sign in before you can view this page.',
        icon: LogIn,
    },
    403: {
        title: 'Access Forbidden',
        description: 'You do not have permission to view this page.',
        icon: Lock,
    },
    404: {
        title: 'Page Not Found',
        description: 'The page you are looking for does not exist, or it has moved.',
        icon: Search,
    },
    405: {
        title: 'Not Allowed',
        description: 'That action is not available on this page.',
        icon: Ban,
    },
    408: {
        title: 'Request Timed Out',
        description: 'The request took too long. Please try again.',
        icon: Clock,
    },
    410: {
        title: 'No Longer Available',
        description: 'This page is gone and will not come back.',
        icon: Trash2,
    },
    413: {
        title: 'Upload Too Large',
        description: 'The file you tried to upload is bigger than we accept.',
        icon: FileX,
    },
    419: {
        title: 'Session Expired',
        description: 'Your session expired for security reasons. Refresh the page and try again.',
        icon: Clock,
    },
    429: {
        title: 'Too Many Requests',
        description: 'You have made too many requests in a short time. Wait a moment, then try again.',
        icon: Hourglass,
    },
    500: {
        title: 'Server Error',
        description: 'Something went wrong on our side. The team has been notified.',
        icon: TriangleAlert,
    },
    502: {
        title: 'Bad Gateway',
        description: 'We could not reach part of the system. Please try again in a moment.',
        icon: Server,
    },
    503: {
        title: 'Down for Maintenance',
        description: 'The badge system is being updated and will be back shortly.',
        icon: Wrench,
    },
    504: {
        title: 'Gateway Timeout',
        description: 'A part of the system took too long to answer. Please try again.',
        icon: Hourglass,
    },
};

const fallback = {
    title: 'Something Went Wrong',
    description: 'An unexpected error occurred.',
    icon: CircleAlert,
};

const error = computed(() => catalog[props.status] ?? fallback);

// The server only forwards 4xx messages; 5xx text is withheld on purpose, so
// whatever arrives here is safe to show and beats the generic wording.
const description = computed(() => props.message || error.value.description);

const usesSiteChrome = computed(() => props.context === 'public' || props.context === 'gallery');
const shell = computed(() => (usesSiteChrome.value ? Layout : 'div'));

// Plain paths, not route() names: the error page is served on every domain the
// app answers on, including the Catch-Em-All host, where the public route names
// resolve to a different origin.
const home = computed(() => ({
    pos: '/pos',
    admin: '/admin',
    catch: '/',
    gallery: '/gallery',
}[props.context] ?? '/'));

const homeLabel = computed(() => (props.context === 'pos' || props.context === 'admin' ? 'Back to Start' : 'Go Home'));

// 5xx and 429 are worth retrying on the spot; a 404 is not.
const canRetry = computed(() => props.status >= 500 || props.status === 408 || props.status === 429);

function goBack() {
    if (window.history.length > 1) {
        window.history.back();

        return;
    }

    window.location.assign(home.value);
}

function reload() {
    window.location.reload();
}
</script>

<template>
    <Head>
        <title>{{ status }} - {{ error.title }}</title>
        <meta head-key="robots" name="robots" content="noindex" />
    </Head>

    <component :is="shell" :class="usesSiteChrome ? null : 'min-h-screen bg-gray-100 flex items-center justify-center p-6'">
        <div
            class="mx-auto w-full max-w-xl"
            :class="usesSiteChrome ? 'px-6 py-16 text-center' : 'text-center'"
        >
            <div class="rounded-2xl bg-white p-8 shadow-lg">
                <component :is="error.icon" class="mx-auto size-12 text-gray-400" />

                <p class="mt-6 font-main text-6xl font-bold text-gray-900">{{ status }}</p>

                <h1 class="mt-2 text-2xl font-semibold text-gray-800">{{ error.title }}</h1>

                <p class="mt-4 text-gray-600">{{ description }}</p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <Button as-child size="lg" class="flex-1">
                        <Link :href="home"><House /> {{ homeLabel }}</Link>
                    </Button>

                    <Button v-if="canRetry" size="lg" variant="outline" class="flex-1" @click="reload">
                        <RefreshCw /> Try Again
                    </Button>

                    <Button v-else size="lg" variant="outline" class="flex-1" @click="goBack">
                        <ArrowLeft /> Go Back
                    </Button>
                </div>

                <p v-if="status >= 500" class="mt-6 text-sm text-gray-500">
                    If this keeps happening, please tell a member of Fursuit Badge staff.
                </p>
            </div>
        </div>
    </component>
</template>
