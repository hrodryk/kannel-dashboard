<?php

namespace App\Enums;

enum EndpointStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unreachable = 'unreachable';
    case Disabled = 'disabled';
    case Idle = 'idle';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Degraded => 'Degraded',
            self::Unreachable => 'Unreachable',
            self::Disabled => 'Disabled',
            self::Idle => 'Idle',
        };
    }
}
