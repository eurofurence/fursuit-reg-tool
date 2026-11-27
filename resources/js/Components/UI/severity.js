import { Check, CircleX, Info, TriangleAlert } from '@lucide/vue';

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

/*
 * Colours come from the EF tokens in resources/css/app.css. The semantic ones
 * (primary, secondary, destructive, foreground) are re-pointed inside .manage,
 * so the same class string fits the dark admin panel.
 */

/** Solid fill. Buttons, and the block a Tag paints. */
export const SOLID = {
    primary: 'text-primary-foreground bg-primary border-primary hover:bg-primary/90 hover:border-primary/90 focus-visible:ring-primary/40',
    secondary: 'text-secondary-foreground bg-secondary border-secondary hover:bg-secondary/80 hover:border-secondary/80 focus-visible:ring-ring/40',
    success: 'text-white bg-success border-success hover:bg-success/90 hover:border-success/90 focus-visible:ring-success/40',
    info: 'text-white bg-info border-info hover:bg-info/90 hover:border-info/90 focus-visible:ring-info/40',
    warning: 'text-grays-900 bg-warning border-warning hover:bg-warning/90 hover:border-warning/90 focus-visible:ring-warning/40',
    help: 'text-white bg-brand-1-500 border-brand-1-500 hover:bg-brand-1-600 hover:border-brand-1-600 focus-visible:ring-brand-1-500/40',
    danger: 'text-destructive-foreground bg-destructive border-destructive hover:bg-destructive/90 hover:border-destructive/90 focus-visible:ring-destructive/40',
    contrast: 'text-background bg-foreground border-foreground hover:bg-foreground/90 hover:border-foreground/90 focus-visible:ring-ring/40',
};

/** No fill, no border. */
export const TEXT = {
    primary: 'text-primary hover:bg-primary/10 focus-visible:ring-primary/40',
    secondary: 'text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-ring/40',
    success: 'text-success hover:bg-success/10 focus-visible:ring-success/40',
    info: 'text-info hover:bg-info/10 focus-visible:ring-info/40',
    warning: 'text-warning hover:bg-warning/10 focus-visible:ring-warning/40',
    help: 'text-brand-1-500 hover:bg-brand-1-500/10 focus-visible:ring-brand-1-500/40',
    danger: 'text-destructive hover:bg-destructive/10 focus-visible:ring-destructive/40',
    contrast: 'text-foreground hover:bg-foreground/10 focus-visible:ring-ring/40',
};

/** Border only. */
export const OUTLINED = {
    primary: 'text-primary border-primary hover:bg-primary/10 focus-visible:ring-primary/40',
    secondary: 'text-muted-foreground border-input hover:bg-muted hover:text-foreground focus-visible:ring-ring/40',
    success: 'text-success border-success hover:bg-success/10 focus-visible:ring-success/40',
    info: 'text-info border-info hover:bg-info/10 focus-visible:ring-info/40',
    warning: 'text-warning border-warning hover:bg-warning/10 focus-visible:ring-warning/40',
    help: 'text-brand-1-500 border-brand-1-500 hover:bg-brand-1-500/10 focus-visible:ring-brand-1-500/40',
    danger: 'text-destructive border-destructive hover:bg-destructive/10 focus-visible:ring-destructive/40',
    contrast: 'text-foreground border-foreground hover:bg-foreground/10 focus-visible:ring-ring/40',
};

/*
 * Tag's fill. Deliberately not SOLID: a Tag has no hover or focus ring, and
 * PrimeVue's own Tag preset never defined `secondary` or `contrast` at all, so
 * `<Tag severity="secondary">` rendered with no background. Filling the gaps
 * here is the one intentional behaviour change in this set.
 */
export const TAG = {
    primary: 'bg-primary text-primary-foreground',
    secondary: 'bg-secondary text-secondary-foreground',
    success: 'bg-success text-white',
    info: 'bg-info text-white',
    warning: 'bg-warning text-grays-900',
    help: 'bg-brand-1-500 text-white',
    danger: 'bg-destructive text-destructive-foreground',
    contrast: 'bg-foreground text-background',
};

/*
 * Message's tint. PrimeVue named these `warn`/`error` while Button and Tag used
 * `warning`/`danger`; both spellings are accepted so a call site that already
 * works keeps working.
 */
export const MESSAGE = {
    info: 'bg-info/10 border-info text-info',
    success: 'bg-success/10 border-success text-success',
    warn: 'bg-warning/10 border-warning text-grays-600',
    error: 'bg-error/10 border-error text-error',
    secondary: 'bg-secondary border-grays-400 text-secondary-foreground',
    contrast: 'bg-foreground border-foreground text-background',
};

const MESSAGE_ALIASES = { warning: 'warn', danger: 'error' };

/** Default icons, matching what PrimeVue's Message shipped per severity. */
export const MESSAGE_ICONS = {
    info: Info,
    success: Check,
    warn: TriangleAlert,
    error: CircleX,
    secondary: Info,
    contrast: Info,
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
