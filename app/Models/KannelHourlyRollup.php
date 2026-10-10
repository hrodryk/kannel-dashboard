<?php

namespace App\Models;

use Database\Factories\KannelHourlyRollupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $endpoint_id
 * @property string|null $smsc_name
 * @property Carbon $bucket_hour
 * @property int $mo_total_start
 * @property int $mo_total_end
 * @property int $mt_total_start
 * @property int $mt_total_end
 * @property int $mo_volume
 * @property int $mt_volume
 * @property float $avg_mo_rate
 * @property float $avg_mt_rate
 * @property float $peak_mo_rate
 * @property float $peak_mt_rate
 * @property int $avg_inbound_queue
 * @property int $avg_outbound_queue
 * @property int $peak_inbound_queue
 * @property int $peak_outbound_queue
 * @property int $failed_count
 * @property int $samples
 */
#[Fillable([
    'endpoint_id', 'smsc_name', 'bucket_hour', 'mo_total_start',
    'mo_total_end', 'mt_total_start', 'mt_total_end', 'mo_volume',
    'mt_volume', 'avg_mo_rate', 'avg_mt_rate', 'peak_mo_rate',
    'peak_mt_rate', 'avg_inbound_queue', 'avg_outbound_queue',
    'peak_inbound_queue', 'peak_outbound_queue', 'failed_count', 'samples',
])]
class KannelHourlyRollup extends Model
{
    /** @use HasFactory<KannelHourlyRollupFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'bucket_hour' => 'datetime',
            'mo_total_start' => 'integer',
            'mo_total_end' => 'integer',
            'mt_total_start' => 'integer',
            'mt_total_end' => 'integer',
            'mo_volume' => 'integer',
            'mt_volume' => 'integer',
            'avg_mo_rate' => 'float',
            'avg_mt_rate' => 'float',
            'peak_mo_rate' => 'float',
            'peak_mt_rate' => 'float',
            'avg_inbound_queue' => 'integer',
            'avg_outbound_queue' => 'integer',
            'peak_inbound_queue' => 'integer',
            'peak_outbound_queue' => 'integer',
            'failed_count' => 'integer',
            'samples' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<KannelEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(KannelEndpoint::class, 'endpoint_id');
    }
}
