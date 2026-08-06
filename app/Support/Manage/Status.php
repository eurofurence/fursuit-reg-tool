<?php

namespace App\Support\Manage;

use App\Domain\Checkout\Enums\TseClientStateEnum;
use App\Enum\EventStateEnum;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintCompletionSourceEnum;
use App\Enum\PrinterConditionEnum;
use App\Enum\PrinterStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Enum\PrintVerificationSourceEnum;
use Spatie\ModelStates\State;

/**
 * Single source of truth for how a domain state is presented: label, tone, glyph.
 *
 * The client never derives a colour from a raw value. It receives a tone name and
 * looks that up in the CSS token map, so the table, the badges and the status strip
 * cannot drift apart.
 *
 * This replaces two divergences the Filament panel carried (audit 7.9, 7.10). The same
 * PrintJobStatusEnum printed its raw value in one table and its label() in another, so a
 * job read `queued` on one screen and `Claimed` on the next; and the same enum was coloured
 * through two APIs, one of which used 'secondary', which is not a valid Filament v3 colour
 * and rendered unstyled. One vocabulary, one tone set.
 *
 * Where an enum already owns its wording, the wording is taken from the enum rather than
 * retyped, so there is still only one place to change a label.
 *
 * Icon names are lucide names in kebab-case, resolved by ManageIcon.vue.
 */
final class Status
{
    public const LIVE = 'live';

    public const OK = 'ok';

    public const WARN = 'warn';

    public const IDLE = 'idle';

    public const DANGER = 'danger';

    public const INFO = 'info';

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function make(string $label, string $tone, ?string $icon = null): array
    {
        return ['label' => $label, 'tone' => $tone, 'icon' => $icon];
    }

    /**
     * The default glyph for a tone, for surfaces that have no better icon of their own.
     * Plan 1.3 assigns one per tone; the mappers below override it wherever the state has
     * a more literal picture (a printer, a pause, a shield).
     */
    public static function glyph(string $tone): string
    {
        return match ($tone) {
            self::LIVE => 'signal',
            self::OK => 'circle-check',
            self::WARN => 'circle-dot',
            self::DANGER => 'triangle-alert',
            self::INFO => 'info',
            default => 'circle',
        };
    }

    /**
     * Badge fulfillment, from App\Models\Badge\State_Fulfillment.
     *
     * Labels are the ones BadgeResource's formatStateUsing produced, verbatim.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function badgeFulfillment(State|string|null $status): array
    {
        return match (self::value($status)) {
            'pending' => self::make('Pending', self::WARN, 'clock'),
            'processing' => self::make('Processing', self::WARN, 'loader'),
            'printed' => self::make('Printed', self::OK, 'printer'),
            'ready_for_pickup' => self::make('Ready for Pickup', self::OK, 'circle-check'),
            'picked_up' => self::make('Picked Up', self::OK, 'package-check'),
            default => self::unknown($status),
        };
    }

    /**
     * Badge payment, from App\Models\Badge\State_Payment.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function badgePayment(State|string|null $status): array
    {
        return match (self::value($status)) {
            'unpaid' => self::make('Unpaid', self::IDLE, 'circle'),
            'paid' => self::make('Paid', self::OK, 'circle-check'),
            default => self::unknown($status),
        };
    }

    /**
     * Fursuit moderation, from App\Models\Fursuit\States.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function fursuit(State|string|null $status): array
    {
        return match (self::value($status)) {
            'pending' => self::make('Pending', self::WARN, 'clock'),
            'approved' => self::make('Approved', self::OK, 'circle-check'),
            'rejected' => self::make('Rejected', self::DANGER, 'circle-x'),
            default => self::unknown($status),
        };
    }

    /**
     * Checkout, from App\Domain\Checkout\Models\Checkout\States.
     *
     * The stored values are the states' own uppercase $name strings. CheckoutResource's
     * status filter keyed its options by FQCN instead and therefore matched zero rows
     * (landmine 6); matching on the name is what makes the filter work.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function checkout(State|string|null $status): array
    {
        return match (self::value($status)) {
            'ACTIVE' => self::make('Active', self::WARN, 'circle-dot'),
            'FINISHED' => self::make('Finished', self::OK, 'circle-check'),
            'CANCELLED' => self::make('Cancelled', self::IDLE, 'circle-x'),
            default => self::unknown($status),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function printJob(PrintJobStatusEnum|string|null $status): array
    {
        $case = self::enumCase(PrintJobStatusEnum::class, $status);

        if (! $case instanceof PrintJobStatusEnum) {
            return self::unknown($status);
        }

        $tone = match ($case) {
            PrintJobStatusEnum::Printed => self::OK,
            PrintJobStatusEnum::Failed => self::DANGER,
            PrintJobStatusEnum::Cancelled => self::IDLE,
            default => self::WARN,
        };

        $icon = match ($case) {
            PrintJobStatusEnum::Pending => 'clock',
            PrintJobStatusEnum::Queued => 'hourglass',
            PrintJobStatusEnum::Printing => 'printer',
            PrintJobStatusEnum::Printed => 'circle-check',
            PrintJobStatusEnum::Failed => 'triangle-alert',
            PrintJobStatusEnum::Cancelled => 'circle-x',
            PrintJobStatusEnum::Retrying => 'rotate-ccw',
        };

        return self::make($case->label(), $tone, $icon);
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function printJobType(PrintJobTypeEnum|string|null $type): array
    {
        $case = self::enumCase(PrintJobTypeEnum::class, $type);

        return match ($case) {
            PrintJobTypeEnum::Badge => self::make('Badge', self::INFO, 'id-card'),
            PrintJobTypeEnum::Receipt => self::make('Receipt', self::IDLE, 'receipt'),
            default => self::unknown($type),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function printBatch(PrintBatchStatusEnum|string|null $status): array
    {
        $case = self::enumCase(PrintBatchStatusEnum::class, $status);

        if (! $case instanceof PrintBatchStatusEnum) {
            return self::unknown($status);
        }

        [$tone, $icon] = match ($case) {
            PrintBatchStatusEnum::Draft => [self::IDLE, 'circle'],
            PrintBatchStatusEnum::Ready => [self::INFO, 'circle-dot'],
            PrintBatchStatusEnum::Printing => [self::WARN, 'printer'],
            PrintBatchStatusEnum::Paused => [self::WARN, 'circle-pause'],
            PrintBatchStatusEnum::Completed => [self::OK, 'circle-check'],
            PrintBatchStatusEnum::Cancelled => [self::IDLE, 'circle-x'],
        };

        return self::make($case->label(), $tone, $icon);
    }

    /**
     * The printer's own reported status. Null-safe on purpose: PrinterResource read
     * `$record->status->value` with no null-safe operator, so one printer row with a null
     * status 500'd the whole table (landmine 28).
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function printer(PrinterStatusEnum|string|null $status): array
    {
        $case = self::enumCase(PrinterStatusEnum::class, $status);

        if (! $case instanceof PrinterStatusEnum) {
            return self::make('Unknown', self::IDLE, 'circle-help');
        }

        [$tone, $icon] = match ($case) {
            PrinterStatusEnum::IDLE, PrinterStatusEnum::ONLINE => [self::OK, 'circle-check'],
            PrinterStatusEnum::WORKING, PrinterStatusEnum::BUSY, PrinterStatusEnum::PROCESSING => [self::WARN, 'loader'],
            PrinterStatusEnum::PAUSED => [self::WARN, 'circle-pause'],
            PrinterStatusEnum::OFFLINE => [self::IDLE, 'signal-zero'],
            PrinterStatusEnum::ERROR => [self::DANGER, 'circle-x'],
            PrinterStatusEnum::MEDIA_EMPTY => [self::DANGER, 'circle-minus'],
            PrinterStatusEnum::MEDIA_JAM, PrinterStatusEnum::COVER_OPEN => [self::DANGER, 'triangle-alert'],
            PrinterStatusEnum::UNKNOWN => [self::IDLE, 'circle-help'],
        };

        return self::make($case->getLabel(), $tone, $icon);
    }

    /**
     * The consumables and fault picture the POS has had since 2026_08_05_100300 and admin
     * has never shown (change 27). Tone comes from the enum's own isStop()/isWarning(), so
     * "what stops a print run" is decided in one place.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function printerCondition(PrinterConditionEnum|string|null $condition): array
    {
        $case = self::enumCase(PrinterConditionEnum::class, $condition);

        if (! $case instanceof PrinterConditionEnum) {
            return self::make('Unknown state', self::IDLE, 'circle-help');
        }

        [$tone, $icon] = match (true) {
            $case === PrinterConditionEnum::Ok => [self::OK, 'circle-check'],
            $case === PrinterConditionEnum::Printing => [self::WARN, 'printer'],
            $case === PrinterConditionEnum::Initializing => [self::WARN, 'loader'],
            $case === PrinterConditionEnum::Offline => [self::IDLE, 'signal-zero'],
            $case->isWarning() => [self::WARN, 'triangle-alert'],
            $case->isStop() => [self::DANGER, 'triangle-alert'],
            default => [self::IDLE, 'circle'],
        };

        return self::make($case->label(), $tone, $icon);
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function completionSource(PrintCompletionSourceEnum|string|null $source): array
    {
        $case = self::enumCase(PrintCompletionSourceEnum::class, $source);

        return match ($case) {
            PrintCompletionSourceEnum::Firmware => self::make($case->label(), self::OK, 'circle-check'),
            PrintCompletionSourceEnum::SpoolerOnly => self::make($case->label(), self::WARN, 'circle-dot'),
            PrintCompletionSourceEnum::Operator => self::make($case->label(), self::IDLE, 'user'),
            default => self::unknown($source),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function verificationSource(PrintVerificationSourceEnum|string|null $source): array
    {
        $case = self::enumCase(PrintVerificationSourceEnum::class, $source);

        return match ($case) {
            PrintVerificationSourceEnum::Camera => self::make($case->label(), self::OK, 'camera'),
            PrintVerificationSourceEnum::Operator => self::make($case->label(), self::OK, 'user-check'),
            default => self::unknown($source),
        };
    }

    /**
     * Whether a printed card has been checked by a human or a camera.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function verified(bool $verified): array
    {
        return self::toggle($verified, 'Verified', 'Unverified', self::OK, self::WARN, 'circle-check', 'circle-dot');
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function tseClient(TseClientStateEnum|string|null $state): array
    {
        $case = self::enumCase(TseClientStateEnum::class, $state);

        return match ($case) {
            TseClientStateEnum::REGISTERED => self::make('Registered', self::OK, 'shield-check'),
            TseClientStateEnum::DEREGISTERED => self::make('Deregistered', self::IDLE, 'shield-off'),
            default => self::unknown($state),
        };
    }

    /**
     * Machines carry a bespoke archived_at column rather than SoftDeletes (audit 7.7).
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function machineArchived(bool $archived): array
    {
        return self::toggle($archived, 'Archived', 'Active', self::IDLE, self::OK, 'archive', 'circle-check');
    }

    /**
     * The order window, as the status strip shows it. Event state is computed from the
     * order dates; there is no state column.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function eventOrders(EventStateEnum|string|bool|null $state): array
    {
        $open = $state instanceof EventStateEnum
            ? $state === EventStateEnum::OPEN
            : ($state === true || $state === EventStateEnum::OPEN->value);

        return self::toggle($open, 'Orders open', 'Orders closed', self::LIVE, self::IDLE, 'circle-check', 'circle');
    }

    /**
     * Two-state badge, e.g. Archived/Active or Verified/Unverified.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function toggle(
        bool $state,
        string $trueLabel,
        string $falseLabel,
        string $trueTone = self::OK,
        string $falseTone = self::IDLE,
        ?string $trueIcon = null,
        ?string $falseIcon = null,
    ): array {
        return $state
            ? self::make($trueLabel, $trueTone, $trueIcon)
            : self::make($falseLabel, $falseTone, $falseIcon);
    }

    /**
     * A value the mapping does not know about still renders, rather than blanking a cell
     * or throwing. Nothing in the panel should ever 500 over an unexpected status string.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    private static function unknown(mixed $value): array
    {
        $label = self::value($value);

        return self::make($label === null || $label === '' ? 'Unknown' : $label, self::IDLE, null);
    }

    /**
     * The stored string behind a Spatie state, a backed enum or a plain value.
     */
    private static function value(mixed $value): ?string
    {
        if ($value instanceof State) {
            return $value->getValue();
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private static function enumCase(string $enum, mixed $value): mixed
    {
        if ($value instanceof $enum) {
            return $value;
        }

        $raw = self::value($value);

        return $raw === null ? null : $enum::tryFrom($raw);
    }
}
