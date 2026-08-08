<?php

namespace App\Enum;

/**
 * Physical condition of a card printer, as reported by the print agent.
 *
 * Laravel runs on the public internet while the printer sits on a private LAN,
 * so the server never polls the printer itself. The agent reads the hardware
 * over SNMP, collapses the firmware's raw vocabulary into these cases and
 * uploads the result. See print-agent/docs/snmp/README.md for the OID mapping.
 *
 * Anything that requires a human before printing can continue is a "stop", and
 * an unrecognised reading is treated as one. The system this replaces assumed
 * that the absence of a known error meant success, which is how it managed to
 * report cards as printed while the printer sat jammed.
 */
enum PrinterConditionEnum: string
{
    case Ok = 'ok';
    case Printing = 'printing';
    case RibbonLow = 'ribbon_low';
    case RibbonOut = 'ribbon_out';
    case FilmLow = 'film_low';
    case FilmOut = 'film_out';
    case CardsLow = 'cards_low';
    case CardsOut = 'cards_out';
    case CardJam = 'card_jam';
    case CoverOpen = 'cover_open';
    case RejectBinFull = 'reject_bin_full';
    case ServiceRequired = 'service_required';
    case Offline = 'offline';

    /**
     * The printer waking up on its way into a job. A ZXP9 walks
     * standby -> initializing -> printing_heating, so this is a healthy noise
     * rather than a fault -- but no card may be sent until it passes.
     */
    case Initializing = 'initializing';
    case Unknown = 'unknown';

    /**
     * Whether this condition halts printing until someone intervenes.
     */
    public function isStop(): bool
    {
        return match ($this) {
            self::Ok, self::Printing, self::RibbonLow, self::FilmLow, self::CardsLow => false,
            default => true,
        };
    }

    /**
     * Whether staff should be warned now so they can act before it becomes a stop.
     */
    public function isWarning(): bool
    {
        return in_array($this, [self::RibbonLow, self::FilmLow, self::CardsLow], true);
    }

    /**
     * Colour band for the live POS indicator: blue busy, red stopped, green ready.
     *
     * Deliberately not routed through PrinterStatusEnum, whose jam, media-empty
     * and cover-open cases are all 'warning' -- which the POS renders green. A
     * jammed printer showing the same colour as a ready one is the exact
     * failure this indicator exists to prevent.
     */
    public function severity(): string
    {
        // Busy is checked first: a warming printer is a stop for printing
        // purposes but it is working, not broken, and red would send somebody
        // to a machine that needs nothing doing to it.
        if ($this === self::Printing || $this === self::Initializing) {
            return 'info';
        }

        return $this->isStop() ? 'danger' : 'success';
    }

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Ready',
            self::Printing => 'Printing',
            self::RibbonLow => 'Ribbon low',
            self::RibbonOut => 'Ribbon empty',
            self::FilmLow => 'Transfer film low',
            self::FilmOut => 'Transfer film empty',
            self::CardsLow => 'Card hopper low',
            self::CardsOut => 'Out of cards',
            self::CardJam => 'Card jam',
            self::CoverOpen => 'Cover open',
            self::RejectBinFull => 'Reject bin full',
            self::ServiceRequired => 'Service required',
            self::Offline => 'Printer offline',
            self::Initializing => 'Warming up',
            self::Unknown => 'Unknown state',
        };
    }

    /**
     * What staff need to do about it, shown in the POS alert.
     */
    public function remedy(): ?string
    {
        return match ($this) {
            self::RibbonLow, self::RibbonOut => 'Replace the colour ribbon.',
            self::FilmLow, self::FilmOut => 'Replace the transfer film.',
            self::CardsLow, self::CardsOut => 'Refill the card hopper.',
            self::CardJam => 'Open the printer and clear the jammed card.',
            self::CoverOpen => 'Close the printer cover.',
            self::RejectBinFull => 'Empty the reject bin.',
            self::ServiceRequired => 'Printer needs servicing, check the front panel.',
            self::Offline => 'Check printer power and network cable.',
            // Nothing for anyone to do; it clears on its own.
            self::Initializing => null,
            self::Unknown => 'Check the printer front panel for a message.',
            default => null,
        };
    }
}
