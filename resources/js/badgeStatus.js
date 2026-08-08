/*
 * Attendee-facing badge status wording.
 *
 * Both the badge list and the badge detail page derive their tags from the same
 * two state machines (payment + fulfillment), so the wording lives here rather
 * than being restated per page - they drifted apart before ("Printing" on one
 * page, "Processing" on the other).
 */

export const FULFILLMENT_LABELS = {
    pending: 'Waiting for print',
    processing: 'Printing',
    ready_for_pickup: 'Ready for pickup',
    picked_up: 'Picked up',
};

export function badgeStatusTags(badge) {
    const tags = [];

    if (badge.status_payment === 'unpaid') {
        tags.push({ value: 'Unpaid', severity: 'danger' });
    }

    // While fulfillment is still pending, the fursuit review decides what
    // happens next, so that is the status worth showing.
    if (badge.status_fulfillment === 'pending') {
        const fursuitStatus = badge.fursuit?.status;

        if (fursuitStatus === 'rejected') {
            // Same band as the rejection mail ("Needs a change") - "Rejected" reads as lost order.
            tags.push({ value: 'Needs a change', severity: 'danger' });
        } else if (fursuitStatus === 'approved') {
            tags.push({ value: 'Waiting for print', severity: 'warning' });
        } else {
            tags.push({ value: 'In review', severity: 'warning' });
        }

        return tags;
    }

    if (badge.status_fulfillment === 'processing') {
        tags.push({ value: FULFILLMENT_LABELS.processing, severity: 'info' });
    } else if (badge.status_fulfillment === 'ready_for_pickup') {
        tags.push({ value: FULFILLMENT_LABELS.ready_for_pickup, severity: 'warning' });
    } else if (badge.status_fulfillment === 'picked_up') {
        tags.push({ value: FULFILLMENT_LABELS.picked_up, severity: 'success' });
    }

    return tags;
}
