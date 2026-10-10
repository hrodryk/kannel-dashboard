<?php

namespace App\Models;

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use Database\Factories\KannelAlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property int|null $endpoint_id
 * @property string $name
 * @property AlertRuleType|string $type
 * @property AlertSeverity|string $severity
 * @property array<string, int|float|string> $threshold
 * @property int $cooldown_minutes
 * @property array<int, array{type:string, to?:string, webhook_url?:string, secret?:string, emails?:list<string>}> $channels
 * @property bool $enabled
 * @property string|null $description
 */
#[Fillable([
    'endpoint_id', 'name', 'type', 'severity', 'threshold', 'cooldown_minutes',
    'channels', 'enabled', 'description',
])]
class KannelAlertRule extends Model
{
    /** @use HasFactory<KannelAlertRuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => AlertRuleType::class,
            'severity' => AlertSeverity::class,
            'threshold' => 'array',
            'cooldown_minutes' => 'integer',
            'channels' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function setChannelsAttribute(mixed $channels): void
    {
        if (! is_array($channels)) {
            $this->attributes['channels'] = '[]';

            return;
        }

        $normalized = [];

        foreach ($channels as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $type = isset($channel['type']) && is_string($channel['type']) ? $channel['type'] : 'mail';
            $clean = ['type' => $type];

            if ($type === 'mail') {
                if (isset($channel['to']) && is_string($channel['to'])) {
                    $clean['to'] = $channel['to'];
                }
                if (isset($channel['emails']) && is_array($channel['emails'])) {
                    $clean['emails'] = array_values(array_filter($channel['emails'], 'is_string'));
                }
            }

            if ($type === 'slack') {
                if (isset($channel['webhook_url']) && is_string($channel['webhook_url']) && $channel['webhook_url'] !== '') {
                    $clean['webhook_url'] = $this->encryptChannelSecret($channel['webhook_url']);
                }
            }

            if ($type === 'webhook') {
                if (isset($channel['url']) && is_string($channel['url'])) {
                    $clean['url'] = $channel['url'];
                }
                if (isset($channel['secret']) && is_string($channel['secret']) && $channel['secret'] !== '') {
                    $clean['secret'] = $this->encryptChannelSecret($channel['secret']);
                }
                if (isset($channel['method']) && is_string($channel['method'])) {
                    $clean['method'] = $channel['method'];
                }
                if (isset($channel['headers']) && is_array($channel['headers'])) {
                    $clean['headers'] = $this->encryptWebhookHeaders($channel['headers']);
                }
            }

            $normalized[] = $clean;
        }

        $this->attributes['channels'] = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getChannelsAttribute(mixed $channels): array
    {
        if (is_string($channels)) {
            try {
                $decoded = json_decode($channels, true, 512, JSON_THROW_ON_ERROR);
                $channels = is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $channels = [];
            }
        }
        if (! is_array($channels)) {
            return [];
        }

        $result = [];
        foreach ($channels as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $type = isset($channel['type']) && is_string($channel['type']) ? $channel['type'] : 'mail';
            $clean = ['type' => $type];

            if ($type === 'mail') {
                if (isset($channel['to']) && is_string($channel['to'])) {
                    $clean['to'] = $channel['to'];
                }
                if (isset($channel['emails']) && is_array($channel['emails'])) {
                    $clean['emails'] = array_values(array_filter($channel['emails'], 'is_string'));
                }
            }

            if ($type === 'slack') {
                if (isset($channel['webhook_url'])) {
                    $clean['webhook_url'] = $this->decryptChannelSecret($channel['webhook_url']);
                }
            }

            if ($type === 'webhook') {
                if (isset($channel['url']) && is_string($channel['url'])) {
                    $clean['url'] = $channel['url'];
                }
                if (isset($channel['secret'])) {
                    $clean['secret'] = $this->decryptChannelSecret($channel['secret']);
                }
                if (isset($channel['method']) && is_string($channel['method'])) {
                    $clean['method'] = $channel['method'];
                }
                if (isset($channel['headers']) && is_array($channel['headers'])) {
                    $clean['headers'] = $this->decryptWebhookHeaders($channel['headers']);
                }
            }

            $result[] = $clean;
        }

        return $result;
    }

    /**
     * @return array<int, mixed>
     */
    public function channelsPlain(): array
    {
        return $this->channels;
    }

    /**
     * @return BelongsTo<KannelEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(KannelEndpoint::class, 'endpoint_id');
    }

    /**
     * @return HasMany<KannelAlertEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(KannelAlertEvent::class, 'rule_id');
    }

    private function encryptChannelSecret(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            try {
                $candidate = Crypt::decryptString($value);
                if ($candidate !== '') {
                    return $value;
                }
            } catch (\Throwable) {
                // not encrypted yet → encrypt below
            }
        }

        if (! is_string($value) || $value === '') {
            return '';
        }

        return Crypt::encryptString($value);
    }

    private function decryptChannelSecret(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * @param  array<int|string, mixed>  $headers
     * @return array<int, array{name:string, value:string}>
     */
    private function encryptWebhookHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = isset($entry['name']) && is_string($entry['name']) ? trim($entry['name']) : '';
            if ($name === '') {
                continue;
            }
            $value = isset($entry['value']) && is_string($entry['value']) ? $entry['value'] : '';
            if ($value !== '') {
                $value = $this->encryptChannelSecret($value);
            }
            $result[] = ['name' => $name, 'value' => $value];
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $headers
     * @return array<int, array{name:string, value:string}>
     */
    private function decryptWebhookHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = isset($entry['name']) && is_string($entry['name']) ? trim($entry['name']) : '';
            if ($name === '') {
                continue;
            }
            $valueRaw = isset($entry['value']) && is_string($entry['value']) ? $entry['value'] : '';
            $value = $valueRaw === '' ? '' : (string) ($this->decryptChannelSecret($valueRaw) ?? $valueRaw);
            $result[] = ['name' => $name, 'value' => $value];
        }

        return $result;
    }
}
