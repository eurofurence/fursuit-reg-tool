<?php

namespace App\Enum;

/**
 * How a printed card was confirmed to be the correct card.
 *
 * Verification is deliberately separate from the job lifecycle. Finishing a
 * print and verifying the card that came out are two different questions, and
 * the agent answers them with two independent API calls. A job can be Printed
 * and unverified; verification simply stamps verified_print_at afterwards.
 */
enum PrintVerificationSourceEnum: string
{
    /** Webcam saw the card and matched it against the rendered badge. */
    case Camera = 'camera';

    /** A human looked at the card and confirmed it. */
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Camera => 'Verified by camera',
            self::Operator => 'Verified by staff',
        };
    }
}
