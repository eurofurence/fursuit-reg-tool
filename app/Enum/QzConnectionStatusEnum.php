<?php

namespace App\Enum;

enum QzConnectionStatusEnum: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Connecting = 'connecting';
    case Error = 'error';
    case Reconnecting = 'reconnecting';

    public function getColor(): string
    {
        return match($this) {
            self::Connected => 'green',
            self::Disconnected => 'red',
            self::Connecting => 'yellow',
            self::Error => 'red',
            self::Reconnecting => 'orange',
        };
    }

    public function getLabel(): string
    {
        return match($this) {
            self::Connected => 'Connected',
            self::Disconnected => 'Disconnected',
            self::Connecting => 'Connecting...',
            self::Error => 'Connection Error',
            self::Reconnecting => 'Reconnecting...',
        };
    }
}