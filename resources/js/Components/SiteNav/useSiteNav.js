import { computed, inject } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { TAB_SLOTS, primaryNav, routeNameFor, secondaryNav, visibleNav } from './navItems';

/** Ziggy's route list, published by the inline script in the root template. */
function ziggyConfig() {
    return typeof Ziggy !== 'undefined' ? Ziggy : globalThis.Ziggy;
}

/**
 * One source of truth for what the rail, the tab bar and the footer each render.
 *
 * Matching is Ziggy's `route().current()`; there is no reactive variant of it, and
 * nothing in Wayfinder replaces it either. Left alone it reads `window.location`, which
 * Vue cannot track - and because Inertia keeps the same Layout instance across visits,
 * the nav never re-rendered and the highlight stuck on whatever page was loaded from the
 * server. Ziggy does accept a `location` in its config, so the current visit is fed in
 * from Inertia's own reactive `page.url`: same matcher, and the active state now derives
 * from state Vue tracks rather than from a global it cannot see.
 *
 * `route` comes from the injection ZiggyVue registers on Vue 3 rather than the template
 * mixin, because this runs in script scope. `current()` takes a single pattern and reads
 * its second argument as route params, so patterns are tested one at a time.
 */
export function useSiteNav() {
    const page = usePage();
    const route = inject('route');

    const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
    const catchEmAllActive = computed(() => Boolean(page.props.catchEmAllActive));

    /** A Ziggy router pinned to the page Inertia currently has open. */
    const router = computed(() => {
        const config = ziggyConfig();

        if (! config) {
            return route();
        }

        return route(undefined, undefined, undefined, {
            ...config,
            location: new URL(page.url, config.url ?? window.location.origin),
        });
    });

    const decorate = (items) => items.map((item) => ({
        ...item,
        href: item.external ? item.href : route(routeNameFor(item, isAuthenticated.value)),
        active: ! item.external && Boolean(item.match?.some((pattern) => router.value.current(pattern))),
    }));

    const context = computed(() => ({
        isAuthenticated: isAuthenticated.value,
        catchEmAllActive: catchEmAllActive.value,
    }));

    const primary = computed(() => decorate(visibleNav(primaryNav, context.value)));
    const secondary = computed(() => decorate(visibleNav(secondaryNav, context.value)));

    return {
        isAuthenticated,
        catchEmAllActive,
        primary,
        secondary,
        /** What fits in the bottom bar next to "More". */
        tabs: computed(() => primary.value.slice(0, TAB_SLOTS)),
        /** What "More" has to hold: the leftover destinations plus the secondary links. */
        overflow: computed(() => [...primary.value.slice(TAB_SLOTS), ...secondary.value]),
    };
}
