<?php

namespace App\Enum;

enum PrinterStatusSeverityEnum: string
{
    case Fatal = 'FATAL';
    case Error = 'ERROR';
    case Warning = 'WARN';
    case Info = 'INFO';

    public function getLevel(): int
    {
        return match ($this) {
            self::Fatal => 4,
            self::Error => 3,
            self::Warning => 2,
            self::Info => 1,
        };
    }
}
