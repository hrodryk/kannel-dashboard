<?php

namespace App\Console\Commands;

use App\Models\KannelHourlyRollup;
use App\Models\KannelMetricSnapshot;
use App\Models\KannelSmscMetric;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('kannel:prune {--days= : Override retention days for this run}')]
#[Description('Remove Kannel metric snapshots, SMSC metrics, and hourly roll-ups older than retention.')]
class KannelPruneCommand extends Command
{
    public function handle(): int
    {
        $rawDays = $this->option('days');
        if ($rawDays !== null && $rawDays !== '') {
            if (! is_numeric($rawDays)) {
                $this->error('Retention days must be a positive number.');

                return self::INVALID;
            }
            $days = (int) $rawDays;
        } else {
            $days = (int) config('kannel.retention_days', 30);
        }

        if ($days <= 0) {
            $this->error('Retention days must be positive.');

            return self::INVALID;
        }

        $cutoff = Carbon::now()->subDays($days)->startOfDay();

        $snapshotIds = KannelMetricSnapshot::query()
            ->where('polled_at', '<', $cutoff)
            ->pluck('id');

        if ($snapshotIds->isNotEmpty()) {
            $deletedSmsc = KannelSmscMetric::query()->whereIn('snapshot_id', $snapshotIds)->delete();
            $deletedSnapshots = KannelMetricSnapshot::query()->whereIn('id', $snapshotIds)->delete();
        } else {
            $deletedSmsc = 0;
            $deletedSnapshots = 0;
        }

        $deletedRollups = KannelHourlyRollup::query()
            ->where('bucket_hour', '<', $cutoff)
            ->delete();

        $this->info(sprintf(
            'Pruned %d snapshots, %d SMSC metric rows, %d hourly rollups older than %s.',
            $deletedSnapshots,
            $deletedSmsc,
            $deletedRollups,
            $cutoff->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}
