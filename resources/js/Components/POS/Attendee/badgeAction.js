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
    if (badge.fursuit?.status === 'rejected') {
        return null;
    }

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

export const STATUS_LABELS = {
    pending: 'Not printed',
    processing: 'Printing',
    printed: 'Printed',
    ready_for_pickup: 'Ready',
    picked_up: 'Picked up',
};

export function statusPill(badge) {
    if (badge.fursuit?.status === 'rejected') {
        return { text: 'Fursuit rejected', tone: 'pos-pill--bad' };
    }

    if (badge.fursuit?.status === 'pending') {
        return { text: 'Approval pending', tone: 'pos-pill--warn' };
    }

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
