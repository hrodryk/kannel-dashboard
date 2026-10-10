<?php

namespace App\Contracts;

use App\DataObjects\Kannel\KannelSnapshotData;
use App\DataObjects\Kannel\SmscMetricsData;

interface KannelMetricsParser
{
    /**
     * @return array{0: KannelSnapshotData, 1: list<SmscMetricsData>}
     */
    public function parse(string $payload): array;

    public function supports(string $contentType): bool;
}
