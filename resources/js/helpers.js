

/**
 * Whether the pre-convention bulk print run is already behind us, which is what decides between
 * "collect on day one" and "we print yours on site, collect from day two" everywhere it is said.
 *
 * An unset `mass_printed_at` means no run has been scheduled, so it is still ahead - the same
 * answer a future date gives. This has to be a function rather than a bare date comparison at each
 * call site: `new Date(null)` is 1 January 1970, so a plain `< new Date()` on an empty column
 * would tell every attendee the deadline had passed.
 */
export function massPrintRunIsOver(event) {
    return Boolean(event?.mass_printed_at) && new Date(event.mass_printed_at) < new Date();
}

/**
 * The print deadline as a date an attendee can read: "1 August 2026".
 *
 * Fixed to en-GB rather than the browser locale because the deadline is the one date on these
 * pages nobody may misread, and 8/1/2026 means two different days on two different continents.
 * Matches what `BuildsBadgeMail::collectionAnswer()` puts in the mail.
 */
export function formatPrintDate(value) {
    return new Date(value).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
}

export function formatEuroFromCents(cents) {
    return new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'EUR'
    }).format(cents / 100);
}
