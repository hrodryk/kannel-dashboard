<?php

namespace App\Enums;

enum AlertChannelType: string
{
    case Mail = 'mail';
    case Slack = 'slack';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Mail => 'Email',
            self::Slack => 'Slack',
            self::Webhook => 'Webhook',
        };
    }
}
