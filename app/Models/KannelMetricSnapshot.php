<?php

namespace App\Models;

use Database\Factories\KannelMetricSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $endpoint_id
 * @property Carbon $polled_at
 * @property bool $success
 * @property int $raw_size_bytes
 * @property int $duration_ms
 * @property int $retries
 * @property int $uptime_seconds
 * @property int $mo_total
 * @property int $mt_total
 * @property float $mo_rate
 * @property float $mt_rate
 * @property int $inbound_queue
 * @property int $outbound_queue
 * @property int $dlr_queue
 * @property int $received
 * @property int $sent
 * @property int $failed_count
 * @property array<int, array<string, int|string>>|null $errors
 * @property string|null $box_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'endpoint_id', 'polled_at', 'success', 'raw_size_bytes', 'duration_ms',
    'retries', 'uptime_seconds', 'mo_total', 'mt_total', 'mo_rate', 'mt_rate',
    'inbound_queue', 'outbound_queue', 'dlr_queue', 'received', 'sent',
    'failed_count', 'errors', 'box_id',
])]
class KannelMetricSnapshot extends Model
{
    /** @use HasFactory<KannelMetricSnapshotFactory> */
    use HasFactory;

    public $timestamps = true;

    protected function casts(): array
    {
        return [
            'polled_at' => 'datetime',
            'success' => 'boolean',
            'raw_size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'retries' => 'integer',
            'uptime_seconds' => 'integer',
            'mo_total' => 'integer',
            'mt_total' => 'integer',
            'mo_rate' => 'float',
            'mt_rate' => 'float',
            'inbound_queue' => 'integer',
            'outbound_queue' => 'integer',
            'dlr_queue' => 'integer',
            'received' => 'integer',
            'sent' => 'integer',
            'failed_count' => 'integer',
            'errors' => 'array',
        ];
    }

    /**
     * @return BelongsTo<KannelEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(KannelEndpoint::class);
    }

    /**
     * @return HasMany<KannelSmscMetric, $this>
     */
    public function smscMetrics(): HasMany
    {
        return $this->hasMany(KannelSmscMetric::class, 'snapshot_id');
    }
}
