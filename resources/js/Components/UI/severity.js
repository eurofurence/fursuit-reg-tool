/*
 * The severity ramp, once.
 *
 * PrimeVue spreads this table across every component's preset, which is why
 * `severity="warning"` on a Tag and on a Button were free to drift apart. Here
 * the colour for a severity is decided in one place and the components pick the
 * variant they need.
 *
 * Class strings are written out in full and never assembled from fragments:
 * Tailwind scans this file as text, so an interpolated `bg-${colour}-500` would
 * simply not be generated.
 */

/** Solid fill. Buttons, and the block a Tag paints. */
export const SOLID = {
    primary: 'text-primary-inverse bg-primary border-primary hover:bg-primary-hover hover:border-primary-hover focus:ring-primary',
    secondary: 'text-white dark:text-surface-900 bg-surface-500 dark:bg-surface-400 border-surface-500 dark:border-surface-400 hover:bg-surface-600 dark:hover:bg-surface-300 hover:border-surface-600 dark:hover:border-surface-300 focus:ring-surface-400/50 dark:focus:ring-surface-300/50',
    success: 'text-white dark:text-green-900 bg-green-500 dark:bg-green-400 border-green-500 dark:border-green-400 hover:bg-green-600 dark:hover:bg-green-300 hover:border-green-600 dark:hover:border-green-300 focus:ring-green-400/50 dark:focus:ring-green-300/50',
    info: 'text-white dark:text-surface-900 bg-blue-500 dark:bg-blue-400 border-blue-500 dark:border-blue-400 hover:bg-blue-600 dark:hover:bg-blue-300 hover:border-blue-600 dark:hover:border-blue-300 focus:ring-blue-400/50 dark:focus:ring-blue-300/50',
    warning: 'text-white dark:text-surface-900 bg-orange-500 dark:bg-orange-400 border-orange-500 dark:border-orange-400 hover:bg-orange-600 dark:hover:bg-orange-300 hover:border-orange-600 dark:hover:border-orange-300 focus:ring-orange-400/50 dark:focus:ring-orange-300/50',
    help: 'text-white dark:text-surface-900 bg-purple-500 dark:bg-purple-400 border-purple-500 dark:border-purple-400 hover:bg-purple-600 dark:hover:bg-purple-300 hover:border-purple-600 dark:hover:border-purple-300 focus:ring-purple-400/50 dark:focus:ring-purple-300/50',
    danger: 'text-white dark:text-surface-900 bg-red-500 dark:bg-red-400 border-red-500 dark:border-red-400 hover:bg-red-600 dark:hover:bg-red-300 hover:border-red-600 dark:hover:border-red-300 focus:ring-red-400/50 dark:focus:ring-red-300/50',
    contrast: 'text-white dark:text-surface-900 bg-surface-900 dark:bg-surface-0 border-surface-900 dark:border-surface-0 hover:bg-surface-800 dark:hover:bg-surface-100 hover:border-surface-800 dark:hover:border-surface-100 focus:ring-surface-500 dark:focus:ring-surface-400',
};

/** No fill, no border. */
export const TEXT = {
    primary: 'text-primary hover:bg-primary-300/20 focus:ring-primary',
    secondary: 'text-surface-500 dark:text-surface-300 hover:bg-surface-300/20 focus:ring-surface-400/50 dark:focus:ring-surface-300/50',
    success: 'text-green-500 dark:text-green-400 hover:bg-green-300/20 focus:ring-green-400/50 dark:focus:ring-green-300/50',
    info: 'text-blue-500 dark:text-blue-400 hover:bg-blue-300/20 focus:ring-blue-400/50 dark:focus:ring-blue-300/50',
    warning: 'text-orange-500 dark:text-orange-400 hover:bg-orange-300/20 focus:ring-orange-400/50 dark:focus:ring-orange-300/50',
    help: 'text-purple-500 dark:text-purple-400 hover:bg-purple-300/20 focus:ring-purple-400/50 dark:focus:ring-purple-300/50',
    danger: 'text-red-500 dark:text-red-400 hover:bg-red-300/20 focus:ring-red-400/50 dark:focus:ring-red-300/50',
    contrast: 'text-surface-900 dark:text-surface-0 hover:bg-surface-900/10 dark:hover:bg-[rgba(255,255,255,0.03)] focus:ring-surface-500 dark:focus:ring-surface-400',
};

/** Border only. */
export const OUTLINED = {
    primary: 'text-primary border-primary hover:bg-primary-300/20 focus:ring-primary',
    secondary: 'text-surface-500 dark:text-surface-300 border-surface-500 hover:bg-surface-300/20 focus:ring-surface-400/50 dark:focus:ring-surface-300/50',
    success: 'text-green-500 border-green-500 hover:bg-green-300/20 focus:ring-green-400/50 dark:focus:ring-green-300/50',
    info: 'text-blue-500 border-blue-500 hover:bg-blue-300/20 focus:ring-blue-400/50 dark:focus:ring-blue-300/50',
    warning: 'text-orange-500 border-orange-500 hover:bg-orange-300/20 focus:ring-orange-400/50 dark:focus:ring-orange-300/50',
    help: 'text-purple-500 border-purple-500 hover:bg-purple-300/20 focus:ring-purple-400/50 dark:focus:ring-purple-300/50',
    danger: 'text-red-500 border-red-500 hover:bg-red-300/20 focus:ring-red-400/50 dark:focus:ring-red-300/50',
    contrast: 'text-surface-900 dark:text-surface-0 border-surface-900 dark:border-surface-0 hover:bg-surface-900/10 dark:hover:bg-[rgba(255,255,255,0.03)] focus:ring-surface-500 dark:focus:ring-surface-400',
};

/*
 * Tag's fill. Deliberately not SOLID: a Tag has no hover or focus ring, and
 * PrimeVue's own Tag preset never defined `secondary` or `contrast` at all, so
 * `<Tag severity="secondary">` rendered with no background. Filling the gaps
 * here is the one intentional behaviour change in this set.
 */
export const TAG = {
    primary: 'bg-primary dark:bg-primary text-primary-inverse',
    secondary: 'bg-surface-500 dark:bg-surface-400 text-white dark:text-surface-900',
    success: 'bg-green-500 dark:bg-green-400 text-white dark:text-green-900',
    info: 'bg-blue-500 dark:bg-blue-400 text-white dark:text-surface-900',
    warning: 'bg-orange-500 dark:bg-orange-400 text-white dark:text-surface-900',
    help: 'bg-purple-500 dark:bg-purple-400 text-white dark:text-surface-900',
    danger: 'bg-red-500 dark:bg-red-400 text-white dark:text-surface-900',
    contrast: 'bg-surface-900 dark:bg-surface-0 text-white dark:text-surface-900',
};

/*
 * Message's tint. PrimeVue named these `warn`/`error` while Button and Tag used
 * `warning`/`danger`; both spellings are accepted so a call site that already
 * works keeps working.
 */
export const MESSAGE = {
    info: 'bg-blue-100/70 dark:bg-blue-500/20 border-blue-500 dark:border-blue-400 text-blue-700 dark:text-blue-300',
    success: 'bg-green-100/70 dark:bg-green-500/20 border-green-500 dark:border-green-400 text-green-700 dark:text-green-300',
    warn: 'bg-orange-100/70 dark:bg-orange-500/20 border-orange-500 dark:border-orange-400 text-orange-700 dark:text-orange-300',
    error: 'bg-red-100/70 dark:bg-red-500/20 border-red-500 dark:border-red-400 text-red-700 dark:text-red-300',
    secondary: 'bg-surface-100 dark:bg-surface-800 border-surface-500 dark:border-surface-400 text-surface-700 dark:text-surface-300',
    contrast: 'bg-surface-900 dark:bg-surface-0 border-surface-900 dark:border-surface-0 text-white dark:text-surface-900',
};

const MESSAGE_ALIASES = { warning: 'warn', danger: 'error' };

/** Default icons, matching what PrimeVue's Message shipped per severity. */
export const MESSAGE_ICONS = {
    info: 'pi pi-info-circle',
    success: 'pi pi-check',
    warn: 'pi pi-exclamation-triangle',
    error: 'pi pi-times-circle',
    secondary: 'pi pi-info-circle',
    contrast: 'pi pi-info-circle',
};

/*
 * Only the Message tables accept the warning/danger spellings. Aliasing every
 * table would make `<UiButton severity="warn">` silently render as primary,
 * hiding a typo that PrimeVue left visibly unstyled.
 */
const ALIASED = new Set([MESSAGE, MESSAGE_ICONS]);
const warned = new Set();

/**
 * Look a severity up in one of the tables above.
 *
 * `null` means primary — PrimeVue's convention, and the reason so many call
 * sites write `<Button label="Save" />` with no severity at all.
 */
export function severityClass(table, severity, fallback = 'primary') {
    const key = severity ?? fallback;

    if (table[key]) {
        return table[key];
    }

    const aliased = ALIASED.has(table) ? table[MESSAGE_ALIASES[key]] : undefined;
    if (aliased) {
        return aliased;
    }

    // Falling through means the severity was not one this table knows. It still
    // renders, but silently — say so, or a typo looks like a deliberate style.
    // Once per key: this is reached from computeds, so an unknown severity in a
    // table column would otherwise warn on every recompute.
    if (import.meta.env?.DEV && !warned.has(key)) {
        warned.add(key);
        console.warn(`[UI] unknown severity "${key}"; falling back to "${fallback}".`);
    }

    return table[fallback];
}
