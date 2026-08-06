/*
 * The one thing a badge needs next.
 *
 * The desk works a queue of physical cards, so a badge row shows exactly one
 * button. Which one is not a choice the staff should have to make: it falls out
 * of the badge's two state machines, in the order money → print → hand out.
 *
 * A zero-total badge that is somehow still "unpaid" (free badges, prepaid
 * allowance) must not send anyone into a 0,00 € checkout, so it skips the pay
 * step instead of dead-ending there.
 */

export const HANDOUT_STATES = ['processing', 'printed', 'ready_for_pickup'];

export function isPayable(badge) {
    return badge.status_payment === 'unpaid' && (badge.total ?? 0) > 0;
}

export function isHandoutable(badge) {
    return HANDOUT_STATES.includes(badge.status_fulfillment);
}

export function badgeAction(badge) {
    // A rejected fursuit is rejected for the gallery and the Catch-Em-All game
    // only. The attendee still ordered a badge, still paid for it and still
    // collects it at the desk, so rejection changes nothing about this queue.
    if (badge.status_fulfillment === 'picked_up') {
        return null;
    }

    if (isPayable(badge)) {
        return 'pay';
    }

    if (badge.status_fulfillment === 'pending') {
        return 'print';
    }

    if (isHandoutable(badge)) {
        return 'handout';
    }

    return null;
}

export const ACTION_LABELS = {
    pay: 'Pay',
    print: 'Print',
    handout: 'Hand out',
};

/*
 * Approval only governs the gallery and the Catch-Em-All game. Shown as a
 * quiet aside so staff know why a suit is missing from the gallery, without
 * suggesting the badge cannot be printed or handed over.
 */
export function approvalNote(badge) {
    switch (badge.fursuit?.status) {
        case 'rejected':
            return { text: 'not in gallery', tone: 'pos-pill--warn' };
        case 'pending':
            return { text: 'approval pending', tone: '' };
        default:
            return null;
    }
}

export const STATUS_LABELS = {
    pending: 'Not printed',
    processing: 'Printing',
    printed: 'Printed',
    ready_for_pickup: 'Ready',
    picked_up: 'Picked up',
};

/*
 * The pill states where the badge is in the pickup queue. Fursuit approval is
 * a separate axis — it decides gallery and Catch-Em-All, not the desk — so it
 * gets its own chip instead of hiding whether this badge is ready to hand over.
 */
export function statusPill(badge) {
    switch (badge.status_fulfillment) {
        case 'pending':
            return { text: STATUS_LABELS.pending, tone: '' };
        case 'processing':
            return { text: STATUS_LABELS.processing, tone: 'pos-pill--accent' };
        case 'printed':
            return { text: STATUS_LABELS.printed, tone: 'pos-pill--accent' };
        case 'ready_for_pickup':
            return { text: STATUS_LABELS.ready_for_pickup, tone: 'pos-pill--good' };
        case 'picked_up':
            return { text: STATUS_LABELS.picked_up, tone: 'pos-pill--good' };
        default:
            return { text: badge.status_fulfillment, tone: '' };
    }
}
