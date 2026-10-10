<?php

namespace App\DataObjects\Kannel;

use DateTimeImmutable;

final readonly class KannelSnapshotData
{
    /**
     * @param  array<int, array{code: string, count: int, smsc?: string, description?: string}>  $errors
     */
    public function __construct(
        public int $uptimeSeconds = 0,
        public int $moTotal = 0,
        public int $mtTotal = 0,
        public int $inboundQueue = 0,
        public int $outboundQueue = 0,
        public int $dlrQueue = 0,
        public int $failedCount = 0,
        public int $received = 0,
        public int $sent = 0,
        public int $smsQueued = 0,
        public int $storeSize = 0,
        public int $storeUsed = 0,
        public int $bufferedMessages = 0,
        public array $errors = [],
        public ?string $boxId = null,
        public ?DateTimeImmutable $collectedAt = null,
    ) {}

    public function collectedAt(): DateTimeImmutable
    {
        return $this->collectedAt ?? new DateTimeImmutable;
    }
}
