/*
 * Ledger skin for PrimeVue dialogs.
 *
 * PrimeVue runs unstyled with the local Lara preset, so a <Dialog> has no p-*
 * hooks to target: every section carries the preset's Tailwind utilities. A
 * component-level `pt` replaces the preset's classes for the sections named
 * here (mergeProps is off by default), which is how the dialog drops its
 * shadow, its 8px radius and the zinc greys in one go.
 *
 * The classes themselves live unscoped in resources/css/pos.css because the
 * overlay is teleported to <body>, outside .pos. `pos-surface` on the mask
 * re-points the surface ramp for the widgets rendered inside the dialog.
 */
export const posDialogPt = {
    mask: {
        class: ['pos-dialog__mask', 'pos-surface', 'transition-all', 'duration-300', 'has-[.mask-active]:bg-transparent'],
    },
    root: { class: 'pos-dialog' },
    header: { class: 'pos-dialog__head' },
    title: { class: 'pos-dialog__title' },
    content: { class: 'pos-dialog__body' },
    footer: { class: 'pos-dialog__foot' },
    closeButton: { class: 'pos-dialog__close' },
    closeButtonIcon: { class: ['inline-block', 'w-4', 'h-4'] },
};

/*
 * Popup menus are teleported too. `pos-surface` on the root re-points the
 * surface ramp, so the preset's own hover and focus classes land on ledger
 * greys instead of zinc.
 */
export const posMenuPt = {
    root: { class: ['pos-menu', 'pos-surface'] },
};
