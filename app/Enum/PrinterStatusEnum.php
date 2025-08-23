<?php

namespace App\Enum;

enum PrinterStatusEnum: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Busy = 'busy';
    case Error = 'error';
    case MediaEmpty = 'media-empty';
    case MediaJam = 'media-jam';
    case CoverOpen = 'cover-open';
    case Paused = 'paused';
    case Unknown = 'unknown';

    public static function fromQzStatusCode(string $code): self
    {
        return match ($code) {
            'online' => self::Online,
            'offline' => self::Offline,
            'media-empty' => self::MediaEmpty,
            'media-jam' => self::MediaJam,
            'cover-open' => self::CoverOpen,
            'paused' => self::Paused,
            default => self::Unknown,
        };
    }

    public function requiresAttention(): bool
    {
        return in_array($this, [
            self::Offline,
            self::Error,
            self::MediaEmpty,
            self::MediaJam,
            self::CoverOpen,
        ]);
    }
}
