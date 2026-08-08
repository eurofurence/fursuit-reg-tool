<?php

namespace App\Enum;

/**
 * How we know a print job finished.
 *
 * Recorded on every transition to Printed so it is always possible to ask which
 * cards rest on solid evidence and which do not. The system this replaces
 * inferred completion from a ten second timer and recorded jammed cards as
 * printed, so "we do not know" is no longer an acceptable answer.
 *
 * Distinct from PrintVerificationSourceEnum, which answers the separate
 * question of whether the card that came out was the right one.
 */
enum PrintCompletionSourceEnum: string
{
    /** Printer firmware reported the job done over SNMP. Strongest signal. */
    case Firmware = 'firmware';

    /**
     * The Windows spooler consumed the job and nothing contradicted it, but the
     * firmware never confirmed. Believable, but not proof a card exists.
     */
    case SpoolerOnly = 'spooler_only';

    /** A human declared it done, typically after clearing a fault by hand. */
    case Operator = 'operator';

    /**
     * The agent's own records said this card had already printed here.
     *
     * Written when a job comes back after its lease lapsed -- the agent was
     * closed or the host rebooted mid-run -- and the station's local store
     * shows the card was already confirmed. The evidence is real but second
     * hand, so it is recorded as its own source rather than dressed up as a
     * fresh firmware confirmation.
     */
    case Recovered = 'recovered';

    public function isAuthoritative(): bool
    {
        return $this === self::Firmware;
    }

    public function label(): string
    {
        return match ($this) {
            self::Firmware => 'Confirmed by printer',
            self::SpoolerOnly => 'Spooler only',
            self::Operator => 'Marked done by staff',
            self::Recovered => 'Recovered from the agent after a restart',
        };
    }
}
