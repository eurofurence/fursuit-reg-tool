import { CircleHelp, IdCard, Images, MapPin, Trophy } from 'lucide-vue-next';

/**
 * The public site's destinations, defined once and rendered three times: as the desktop
 * pill rail, as the mobile bottom tab bar, and as the footer link row. Keeping one list
 * is the point - the old header and footer disagreed about what the site even contained.
 *
 * `routeName` and `match` are resolved into a concrete `href` and `active` flag by
 * `useSiteNav()`; components render what it hands them and never call `route()`
 * themselves, so the highlight tracks client-side navigation.
 * `short` is the tab bar label, which has ~64px to work with.
 */
export const primaryNav = [
    {
        key: 'badges',
        label: 'My Badges',
        short: 'Badges',
        icon: IdCard,
        routeName: 'badges.index',
        // Signed-out visitors have no badge list; the landing page is their equivalent.
        guestRouteName: 'welcome',
        match: ['welcome', 'badges.*'],
    },
    {
        key: 'gallery',
        label: 'Gallery',
        short: 'Gallery',
        icon: Images,
        routeName: 'gallery.index',
        match: ['gallery.*'],
    },
    {
        key: 'catch',
        label: 'Catch-Em-All',
        short: 'Catch',
        icon: Trophy,
        routeName: 'info.catch-em-all',
        match: ['info.catch-em-all'],
        // Hidden outside the game window. See HandleInertiaRequests::share().
        requiresCatchEmAll: true,
    },
    {
        key: 'pickup',
        label: 'Pickup',
        short: 'Pickup',
        icon: MapPin,
        routeName: 'info.pickup',
        match: ['info.pickup'],
    },
    {
        key: 'faq',
        label: 'FAQ',
        short: 'FAQ',
        icon: CircleHelp,
        routeName: 'info.faq',
        match: ['info.faq'],
    },
];

/**
 * Everything that does not earn a tab. Reached through "More" on mobile and the footer
 * row on desktop. Empty today - every destination fits in the primary nav - but the
 * plumbing stays so adding one is a single entry here.
 */
export const secondaryNav = [];

export const legalLinks = [
    { key: 'imprint', label: 'Legal Notice', href: 'https://help.eurofurence.org/legal/imprint' },
    { key: 'privacy', label: 'Privacy Policy', href: 'https://help.eurofurence.org/legal/privacy' },
];

/**
 * The nav items a given visitor may see.
 *
 * @param {{ isAuthenticated: boolean, catchEmAllActive: boolean }} context
 */
export function visibleNav(items, { isAuthenticated, catchEmAllActive }) {
    return items.filter((item) => {
        if (item.requiresAuth && !isAuthenticated) {
            return false;
        }

        return !(item.requiresCatchEmAll && !catchEmAllActive);
    });
}

/**
 * The bottom bar holds five slots and the fifth is always "More", so four destinations
 * get a tab and the rest fall through to the sheet. Slicing rather than hard-coding
 * means a hidden Catch-Em-All promotes FAQ into the bar instead of leaving a gap.
 */
export const TAB_SLOTS = 4;

/** The route name an item points at for this visitor. */
export function routeNameFor(item, isAuthenticated) {
    return !isAuthenticated && item.guestRouteName ? item.guestRouteName : item.routeName;
}
