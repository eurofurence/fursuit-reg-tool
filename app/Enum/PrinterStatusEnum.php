<?php

namespace App\Enum;

enum PrinterStatusEnum: string
{
    // Printer operational states (for our internal printer management)
    case IDLE = 'idle';
    case WORKING = 'working';
    case PAUSED = 'paused';
    case OFFLINE = 'offline';
    
    // QZ-Tray specific statuses (for external printer monitoring)  
    case ONLINE = 'online';
    case BUSY = 'busy';
    case PROCESSING = 'processing';
    case ERROR = 'error';
    case MEDIA_EMPTY = 'media-empty';
    case MEDIA_JAM = 'media-jam';
    case COVER_OPEN = 'cover-open';
    case UNKNOWN = 'unknown';

    public static function fromQzStatusCode(string $code): self
    {
        return match ($code) {
            'online' => self::ONLINE,
            'offline' => self::OFFLINE,
            'processing' => self::PROCESSING,
            'media-empty' => self::MEDIA_EMPTY,
            'media-jam' => self::MEDIA_JAM,
            'cover-open' => self::COVER_OPEN,
            'paused' => self::PAUSED,
            'busy' => self::BUSY,
            'error' => self::ERROR,
            default => self::UNKNOWN,
        };
    }

    public function requiresAttention(): bool
    {
        return in_array($this, [
            self::OFFLINE,
            self::ERROR,
            self::MEDIA_EMPTY,
            self::MEDIA_JAM,
            self::COVER_OPEN,
            self::PAUSED,
        ]);
    }

    public function getLabel(): string
    {
        return match($this) {
            self::IDLE => 'Ready',
            self::WORKING => 'Working', 
            self::PAUSED => 'Paused',
            self::OFFLINE => 'Offline',
            self::ONLINE => 'Online',
            self::BUSY => 'Busy',
            self::PROCESSING => 'Processing',
            self::ERROR => 'Error',
            self::MEDIA_EMPTY => 'Media Empty',
            self::MEDIA_JAM => 'Media Jam',
            self::COVER_OPEN => 'Cover Open',
            self::UNKNOWN => 'Unknown',
        };
    }

    public function getIcon(): string
    {
        return match($this) {
            self::IDLE => 'pi pi-check-circle',
            self::WORKING => 'pi pi-spin pi-spinner',
            self::PAUSED => 'pi pi-pause-circle',
            self::OFFLINE => 'pi pi-exclamation-triangle',
            self::ONLINE => 'pi pi-check-circle',
            self::BUSY => 'pi pi-spin pi-spinner',
            self::PROCESSING => 'pi pi-spin pi-spinner',
            self::ERROR => 'pi pi-times-circle',
            self::MEDIA_EMPTY => 'pi pi-minus-circle',
            self::MEDIA_JAM => 'pi pi-exclamation-triangle',
            self::COVER_OPEN => 'pi pi-exclamation-triangle',
            self::UNKNOWN => 'pi pi-question-circle',
        };
    }

    public function getSeverity(): string
    {
        return match($this) {
            self::IDLE => 'success',
            self::WORKING => 'info',
            self::PAUSED => 'warning', 
            self::OFFLINE => 'danger',
            self::ONLINE => 'success',
            self::BUSY => 'info',
            self::PROCESSING => 'info',
            self::ERROR => 'danger',
            self::MEDIA_EMPTY => 'warning',
            self::MEDIA_JAM => 'warning',
            self::COVER_OPEN => 'warning',
            self::UNKNOWN => 'secondary',
        };
    }
}
