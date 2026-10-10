<?php

namespace App\Console\Commands;

use App\Models\KannelEndpoint;
use App\Models\KannelHourlyRollup;
use App\Models\KannelMetricSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('kannel:rollup {--endpoint= : Restrict roll-up to a single endpoint ID}')]
#[Description('Aggregate raw snapshots into hourly buckets for 24h and 7d throughput charts.')]
class KannelRollupCommand extends Command
{
    public function handle(): int
    {
        $endpoints = KannelEndpoint::query()
            ->when($this->option('endpoint'), fn ($q) => $q->where('id', (int) $this->option('endpoint')))
            ->pluck('id');

        if ($endpoints->isEmpty()) {
            return self::SUCCESS;
        }

        $processedBuckets = 0;

        foreach ($endpoints as $endpointId) {
            $processedBuckets += $this->rollupEndpoint((int) $endpointId);
        }

        $this->info(sprintf('Upserted %d hourly roll-up buckets.', $processedBuckets));

        return self::SUCCESS;
    }

    private function rollupEndpoint(int $endpointId): int
    {
        $since = Carbon::now()->subDays(max(2, (int) config('kannel.retention_days', 30)));

        $query = KannelMetricSnapshot::query()
            ->where('endpoint_id', $endpointId)
            ->where('success', true)
            ->where('polled_at', '>=', $since)
            ->orderBy('polled_at', 'asc');

        $buckets = [];

        /** @var KannelMetricSnapshot $snapshot */
        foreach ($query->cursor() as $snapshot) {
            $hour = Carbon::instance($snapshot->polled_at)->startOfHour()->toDateTimeString();
            $key = $hour.'|0';
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'endpoint_id' => $endpointId,
                    'smsc_name' => null,
                    'bucket_hour' => $hour,
                    'samples' => 0,
                    'mo_total_start' => $snapshot->mo_total,
                    'mo_total_end' => $snapshot->mo_total,
                    'mt_total_start' => $snapshot->mt_total,
                    'mt_total_end' => $snapshot->mt_total,
                    'sum_mo_rate' => 0.0,
                    'sum_mt_rate' => 0.0,
                    'peak_mo_rate' => 0.0,
                    'peak_mt_rate' => 0.0,
                    'sum_inbound_queue' => 0,
                    'sum_outbound_queue' => 0,
                    'peak_inbound_queue' => 0,
                    'peak_outbound_queue' => 0,
                    'failed_count' => 0,
                ];
            }
            $b = &$buckets[$key];
            $b['samples']++;
            $b['mo_total_start'] = min($b['mo_total_start'], $snapshot->mo_total);
            $b['mo_total_end'] = max($b['mo_total_end'], $snapshot->mo_total);
            $b['mt_total_start'] = min($b['mt_total_start'], $snapshot->mt_total);
            $b['mt_total_end'] = max($b['mt_total_end'], $snapshot->mt_total);
            $b['sum_mo_rate'] += $snapshot->mo_rate;
            $b['sum_mt_rate'] += $snapshot->mt_rate;
            $b['peak_mo_rate'] = max($b['peak_mo_rate'], $snapshot->mo_rate);
            $b['peak_mt_rate'] = max($b['peak_mt_rate'], $snapshot->mt_rate);
            $b['sum_inbound_queue'] += $snapshot->inbound_queue;
            $b['sum_outbound_queue'] += $snapshot->outbound_queue;
            $b['peak_inbound_queue'] = max($b['peak_inbound_queue'], $snapshot->inbound_queue);
            $b['peak_outbound_queue'] = max($b['peak_outbound_queue'], $snapshot->outbound_queue);
            $b['failed_count'] += $snapshot->failed_count;
            unset($b);
        }

        $rows = [];
        foreach ($buckets as $b) {
            $samples = max(1, $b['samples']);
            $rows[] = [
                'endpoint_id' => $b['endpoint_id'],
                'smsc_name' => $b['smsc_name'],
                'bucket_hour' => $b['bucket_hour'],
                'mo_total_start' => $b['mo_total_start'],
                'mo_total_end' => $b['mo_total_end'],
                'mt_total_start' => $b['mt_total_start'],
                'mt_total_end' => $b['mt_total_end'],
                'mo_volume' => max(0, $b['mo_total_end'] - $b['mo_total_start']),
                'mt_volume' => max(0, $b['mt_total_end'] - $b['mt_total_start']),
                'avg_mo_rate' => round($b['sum_mo_rate'] / $samples, 4),
                'avg_mt_rate' => round($b['sum_mt_rate'] / $samples, 4),
                'peak_mo_rate' => $b['peak_mo_rate'],
                'peak_mt_rate' => $b['peak_mt_rate'],
                'avg_inbound_queue' => (int) round($b['sum_inbound_queue'] / $samples),
                'avg_outbound_queue' => (int) round($b['sum_outbound_queue'] / $samples),
                'peak_inbound_queue' => $b['peak_inbound_queue'],
                'peak_outbound_queue' => $b['peak_outbound_queue'],
                'failed_count' => $b['failed_count'],
                'samples' => $b['samples'],
                'updated_at' => now()->toDateTimeString(),
                'created_at' => now()->toDateTimeString(),
            ];
        }

        if ($rows === []) {
            return 0;
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            KannelHourlyRollup::query()->upsert(
                $chunk,
                ['endpoint_id', 'smsc_name', 'bucket_hour'],
                [
                    'mo_total_start', 'mo_total_end', 'mt_total_start', 'mt_total_end',
                    'mo_volume', 'mt_volume', 'avg_mo_rate', 'avg_mt_rate',
                    'peak_mo_rate', 'peak_mt_rate', 'avg_inbound_queue',
                    'avg_outbound_queue', 'peak_inbound_queue', 'peak_outbound_queue',
                    'failed_count', 'samples', 'updated_at',
                ],
            );
        }

        return count($rows);
    }
}
