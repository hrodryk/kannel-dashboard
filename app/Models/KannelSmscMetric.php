<?php

namespace App\Models;

use App\Enums\SMSCState;
use Database\Factories\KannelSmscMetricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $snapshot_id
 * @property int $endpoint_id
 * @property string $smsc_name
 * @property string|null $smsc_id
 * @property SMSCState|string $smsc_state
 * @property int $latency_ms
 * @property int $mo_count
 * @property int $mt_count
 * @property int $received
 * @property int $sent
 * @property int $failed
 * @property int $queued
 * @property Carbon|null $last_seen_at
 */
#[Fillable([
    'snapshot_id', 'endpoint_id', 'smsc_name', 'smsc_id', 'smsc_state',
    'latency_ms', 'mo_count', 'mt_count', 'received', 'sent', 'failed',
    'queued', 'last_seen_at',
])]
class KannelSmscMetric extends Model
{
    /** @use HasFactory<KannelSmscMetricFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'smsc_state' => SMSCState::class,
            'latency_ms' => 'integer',
            'mo_count' => 'integer',
            'mt_count' => 'integer',
            'received' => 'integer',
            'sent' => 'integer',
            'failed' => 'integer',
            'queued' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<KannelMetricSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(KannelMetricSnapshot::class, 'snapshot_id');
    }

    /**
     * @return BelongsTo<KannelEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(KannelEndpoint::class, 'endpoint_id');
    }
}
