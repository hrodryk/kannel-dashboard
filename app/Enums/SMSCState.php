<?php

namespace App\Enums;

enum SMSCState: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Binding = 'binding';
    case Reconnecting = 'reconnecting';
    case Idle = 'idle';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Disconnected => 'Disconnected',
            self::Binding => 'Binding',
            self::Reconnecting => 'Reconnecting',
            self::Idle => 'Idle',
            self::Unknown => 'Unknown',
        };
    }

    public function isOperational(): bool
    {
        return $this === self::Connected;
    }

    public function isProblematic(): bool
    {
        return in_array($this, [self::Disconnected, self::Unknown], true);
    }
}
