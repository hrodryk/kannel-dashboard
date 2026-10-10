<?php

namespace App\Models;

use App\Enums\EndpointStatus;
use Database\Factories\KannelEndpointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string|null $admin_user
 * @property string|null $admin_password
 * @property string|null $admin_api_key_header
 * @property string|null $admin_api_key_value
 * @property string|null $admin_url
 * @property string|null $admin_username
 * @property int $poll_interval_seconds
 * @property int $timeout_seconds
 * @property int $consecutive_failures
 * @property EndpointStatus|string $status
 * @property bool $enabled
 * @property Carbon|null $last_polled_at
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_ok_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'url', 'admin_url', 'admin_username', 'admin_user', 'admin_password', 'admin_api_key_header',
    'admin_api_key_value', 'poll_interval_seconds', 'timeout_seconds',
    'enabled', 'status',
])]
#[Hidden(['admin_password', 'admin_api_key_value'])]
class KannelEndpoint extends Model
{
    /** @use HasFactory<KannelEndpointFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'poll_interval_seconds' => 'integer',
            'timeout_seconds' => 'integer',
            'consecutive_failures' => 'integer',
            'status' => EndpointStatus::class,
            'enabled' => 'boolean',
            'last_polled_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    protected function adminPassword(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (\Throwable) {
                    return null;
                }
            },
            set: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                return Crypt::encryptString($value);
            },
        );
    }

    protected function adminApiKeyValue(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (\Throwable) {
                    return null;
                }
            },
            set: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                return Crypt::encryptString($value);
            },
        );
    }

    protected function adminUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): string => (string) $this->url,
            set: function (mixed $value): array {
                return ['url' => $this->normalizeBaseUrl((string) $value)];
            },
        );
    }

    protected function adminUsername(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->admin_user,
            set: fn (?string $value): array => ['admin_user' => $value === '' ? null : $value],
        );
    }

    private function normalizeBaseUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parts = parse_url($value);
        if ($parts === false) {
            return $value;
        }
        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : 'http://';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $stripPaths = [
            '/status.xml',
            '/status.json',
            '/store-status.xml',
            '/store-status.json',
            '/status',
            '/store-status',
        ];
        foreach ($stripPaths as $strip) {
            if (strcasecmp(substr($path, -strlen($strip)), $strip) === 0) {
                $path = substr($path, 0, -strlen($strip));
                break;
            }
        }
        $path = rtrim($path, '/');

        return rtrim($scheme.$host.$port.$path, '/');
    }

    /**
     * @return HasMany<KannelMetricSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(KannelMetricSnapshot::class, 'endpoint_id');
    }

    /**
     * @return HasMany<KannelSmscMetric, $this>
     */
    public function smscMetrics(): HasMany
    {
        return $this->hasMany(KannelSmscMetric::class, 'endpoint_id');
    }

    /**
     * @return HasMany<KannelAlertRule, $this>
     */
    public function alertRules(): HasMany
    {
        return $this->hasMany(KannelAlertRule::class, 'endpoint_id');
    }

    public function statusUrl(string $format = 'xml'): string
    {
        $path = $format === 'json'
            ? config('kannel.admin.status_path_json', '/status.json')
            : config('kannel.admin.status_path_xml', '/status.xml');

        return rtrim($this->url, '/').'/'.ltrim($path, '/');
    }

    public function storeStatusUrl(): string
    {
        $path = config('kannel.admin.store_status_path_xml', '/store-status.xml');

        return rtrim($this->url, '/').'/'.ltrim($path, '/');
    }

    public function markHealthy(?Carbon $at = null): void
    {
        $at ??= now();
        $this->consecutive_failures = 0;
        $this->status = EndpointStatus::Healthy;
        $this->last_polled_at = $at;
        $this->last_success_at = $at;
        $this->last_error = null;
        try {
            $this->timestamps = false;
            $this->save();
        } catch (\Throwable) {
            // ignore persistence failures – transient state still reflected in-memory
        }
    }

    public function markUnhealthy(string $errorMessage, ?Carbon $at = null): void
    {
        $at ??= now();
        $threshold = (int) config('kannel.consecutive_failures_before_unreachable', 3);
        $this->consecutive_failures = (int) $this->consecutive_failures + 1;
        $this->last_polled_at = $at;
        $this->last_error = $errorMessage;
        $this->status = $this->consecutive_failures >= $threshold
            ? EndpointStatus::Unreachable
            : EndpointStatus::Degraded;
        try {
            $this->timestamps = false;
            $this->save();
        } catch (\Throwable) {
            // ignore persistence failures – transient state still reflected in-memory
        }
    }
}
