<?php

namespace App\DataObjects\Kannel;

use App\Enums\SMSCState;
use DateTimeImmutable;

final readonly class SmscMetricsData
{
    public function __construct(
        public string $smscName,
        public SMSCState $state,
        public int $received = 0,
        public int $sent = 0,
        public int $failed = 0,
        public int $queued = 0,
        public int $moCount = 0,
        public int $mtCount = 0,
        public int $latencyMs = 0,
        public ?DateTimeImmutable $lastSeenAt = null,
        public ?string $smscId = null,
    ) {}
}
