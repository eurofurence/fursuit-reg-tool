/*
 * Ledger skin for UiDialog: `<UiDialog v-bind="posDialog" ...>`.
 *
 * The classes live unscoped in resources/css/pos.css because the overlay is
 * teleported to <body>, outside .pos. `pos-surface` on the mask re-points the
 * surface ramp and the kit tokens for the widgets rendered inside the dialog.
 */
export const posDialog = {
    maskClass: 'pos-dialog__mask pos-surface',
    panelClass: 'pos-dialog',
    headerClass: 'pos-dialog__head',
    titleClass: 'pos-dialog__title',
    contentClass: 'pos-dialog__body',
    footerClass: 'pos-dialog__foot',
    closeClass: 'pos-dialog__close',
};
