<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use Database\Factories\KannelAlertEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $rule_id
 * @property int|null $endpoint_id
 * @property string|null $smsc_name
 * @property string $fingerprint
 * @property string $state
 * @property AlertSeverity|string $severity
 * @property string $title
 * @property string $message
 * @property array<string, mixed>|null $payload
 * @property Carbon $fired_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $last_notified_at
 * @property int $notify_attempts
 */
#[Fillable([
    'rule_id', 'endpoint_id', 'smsc_name', 'fingerprint', 'state', 'severity',
    'title', 'message', 'payload', 'fired_at', 'resolved_at',
    'last_notified_at', 'notify_attempts',
])]
class KannelAlertEvent extends Model
{
    /** @use HasFactory<KannelAlertEventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'severity' => AlertSeverity::class,
            'payload' => 'array',
            'fired_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'notify_attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<KannelAlertRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(KannelAlertRule::class, 'rule_id');
    }

    /**
     * @return BelongsTo<KannelEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(KannelEndpoint::class, 'endpoint_id');
    }
}
