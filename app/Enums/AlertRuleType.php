<?php

namespace App\Enums;

enum AlertRuleType: string
{
    case SmscDown = 'smsc_down';
    case QueueExceeded = 'queue_exceeded';
    case ThroughputDrop = 'throughput_drop_below_baseline';
    case EndpointUnreachable = 'endpoint_unreachable';

    public function label(): string
    {
        return match ($this) {
            self::SmscDown => 'SMSC Disconnection',
            self::QueueExceeded => 'Queue Length Threshold',
            self::ThroughputDrop => 'Throughput Drop Below Baseline',
            self::EndpointUnreachable => 'Kannel Endpoint Unreachable',
        };
    }
}
