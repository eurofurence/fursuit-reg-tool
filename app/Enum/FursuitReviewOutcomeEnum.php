<?php

namespace App\Enum;

use App\Support\Manage\Status;

/**
 * What a reviewer can decide about a submitted fursuit.
 *
 * Approval used to be a single yes/no, so a photo that broke a gallery rule but no rule
 * in the Code of Conduct could only be turned away wholesale - and a rejected fursuit
 * never gets printed or handed out, which is a badge lost over a gallery rule. The three
 * outcomes below separate the two questions a reviewer is actually answering:
 *
 *  - Approved: fine on both counts. The card prints, the fursuit may be published.
 *  - Rejected: breaks the Code of Conduct. Nothing prints and nothing is handed out
 *    until the attendee changes the submission. This is the only blocking outcome.
 *  - PublicationBlocked: fine by the Code of Conduct, wrong for the public surfaces -
 *    digital art rather than a photo of a suit, most often. The card prints and is
 *    handed out as normal; the gallery and Catch-Em-All do not show it.
 */
enum FursuitReviewOutcomeEnum: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case PublicationBlocked = 'publication_blocked';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Rejected => 'Rejected (Code of Conduct)',
            self::PublicationBlocked => 'Approved, not published',
        };
    }

    /**
     * The one-line explanation the review page puts under the button, in the reviewer's
     * terms rather than the attendee's.
     */
    public function consequence(): string
    {
        return match ($this) {
            self::Approved => 'Prints, is handed out, and may appear in the gallery and the game.',
            self::Rejected => 'Nothing prints and nothing is handed out until the attendee fixes it.',
            self::PublicationBlocked => 'Prints and is handed out, but never shown in the gallery or the game.',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Approved => Status::OK,
            self::Rejected => Status::DANGER,
            self::PublicationBlocked => Status::WARN,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Approved => 'circle-check',
            self::Rejected => 'circle-x',
            self::PublicationBlocked => 'eye-off',
        };
    }

    /**
     * Whether the attendee has to be told why. Both negative outcomes are useless to the
     * attendee without a reason, and a plain approval has nothing to explain.
     */
    public function requiresReason(): bool
    {
        return $this !== self::Approved;
    }

    /**
     * The single-key shortcut the review page binds. Kept next to the outcome so the page
     * and its help text cannot disagree about which key does what.
     */
    public function shortcut(): string
    {
        return match ($this) {
            self::Approved => 'a',
            self::Rejected => 'r',
            self::PublicationBlocked => 'g',
        };
    }
}
