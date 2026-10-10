<?php

namespace App\Services\Kannel;

use App\DataObjects\Kannel\HttpFetchResult;
use App\Models\KannelEndpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HttpClient
{
    /**
     * @var list<string>
     */
    private const SECRET_HEADER_NAMES = [
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'x-auth-token',
        'x-access-token',
        'x-secret',
        'api-key',
    ];

    public function __construct(
        protected HttpClientFactory $http,
    ) {}

    public function fetchStatus(KannelEndpoint $endpoint, string $format = 'xml', bool $isWebContext = false): HttpFetchResult
    {
        return $this->fetch($endpoint, $endpoint->statusUrl($format), $format, $isWebContext);
    }

    public function fetchStoreStatus(KannelEndpoint $endpoint, bool $isWebContext = false): HttpFetchResult
    {
        return $this->fetch($endpoint, $endpoint->storeStatusUrl(), 'xml', $isWebContext);
    }

    private function fetch(KannelEndpoint $endpoint, string $url, string $format, bool $isWebContext = false): HttpFetchResult
    {
        if ($isWebContext) {
            $maxRetries = 1;
            $base = 0;
            $cap = 0;
        } else {
            $maxRetries = config('kannel.http.retry_max_attempts', 4);
            $base = (int) config('kannel.http.retry_base_seconds', 1);
            $cap = (int) config('kannel.http.retry_cap_seconds', 30);
        }

        $timeout = $endpoint->timeout_seconds > 0
            ? $endpoint->timeout_seconds
            : (int) config('kannel.http.timeout_seconds', 10);
        $connectTimeout = (int) config('kannel.http.connect_timeout_seconds', 5);

        $safeUrl = $this->maskUrlForLogging($url, $endpoint->admin_user);
        $retries = 0;
        $startMs = (int) (microtime(true) * 1000);
        try {
            $logger = Log::channel('kannel');
        } catch (\Throwable) {
            $logger = Log::getLogger();
        }

        $knownSecrets = array_values(array_filter([
            (string) $endpoint->admin_password,
            (string) $endpoint->admin_api_key_value,
            (string) $endpoint->admin_user,
        ], static fn (string $s): bool => $s !== ''));

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $pending = $this->http->timeout($timeout)->connectTimeout($connectTimeout);

                if (! empty($endpoint->admin_user) || ! empty($endpoint->admin_password)) {
                    $pending = $pending->withBasicAuth(
                        (string) $endpoint->admin_user,
                        (string) $endpoint->admin_password,
                    );
                }

                if (! empty($endpoint->admin_api_key_header) && ! empty($endpoint->admin_api_key_value)) {
                    $pending = $pending->withHeaders([
                        $endpoint->admin_api_key_header => $endpoint->admin_api_key_value,
                    ]);
                }

                $response = $pending->accept($format === 'json' ? 'application/json' : 'application/xml,text/xml')->get($url);

                $durationMs = (int) (microtime(true) * 1000) - $startMs;

                if ($response->successful()) {
                    $logger->info('kannel.fetch.success', [
                        'endpoint_id' => $endpoint->id,
                        'url' => $safeUrl,
                        'status' => $response->status(),
                        'duration_ms' => $durationMs,
                        'retries' => $retries,
                        'size_bytes' => strlen($response->body()),
                    ]);

                    return new HttpFetchResult(
                        success: true,
                        status: $response->status(),
                        body: $response->body(),
                        durationMs: $durationMs,
                        retries: $retries,
                    );
                }

                $retries = $attempt + 1;

                $logger->warning('kannel.fetch.http_error', [
                    'endpoint_id' => $endpoint->id,
                    'url' => $safeUrl,
                    'status' => $response->status(),
                    'attempt' => $attempt + 1,
                    'max_attempts' => $maxRetries,
                ]);

                if ($attempt + 1 < $maxRetries) {
                    $this->backoff($base, $cap, $attempt);
                }

                if ($attempt + 1 === $maxRetries) {
                    return $this->failureResult($response->status(), (string) $response->body(), $durationMs, $retries, $knownSecrets);
                }
            } catch (ConnectionException $e) {
                $retries = $attempt + 1;
                $durationMs = (int) (microtime(true) * 1000) - $startMs;

                $logger->warning('kannel.fetch.connection_error', [
                    'endpoint_id' => $endpoint->id,
                    'url' => $safeUrl,
                    'attempt' => $attempt + 1,
                    'max_attempts' => $maxRetries,
                    'error' => $e::class,
                    'message' => $this->maskSecrets($e->getMessage(), $knownSecrets),
                ]);

                if ($attempt + 1 < $maxRetries) {
                    $this->backoff($base, $cap, $attempt);

                    continue;
                }

                return new HttpFetchResult(
                    success: false,
                    status: 0,
                    body: '',
                    durationMs: $durationMs,
                    retries: $retries,
                    errorMessage: $this->maskSecrets($e->getMessage(), $knownSecrets),
                );
            }
        }

        $durationMs = (int) (microtime(true) * 1000) - $startMs;

        return new HttpFetchResult(
            success: false,
            status: 0,
            body: '',
            durationMs: $durationMs,
            retries: $retries,
            errorMessage: 'Unknown fetch failure',
        );
    }

    private function backoff(int $base, int $cap, int $attempt): void
    {
        if ($base <= 0 || $cap <= 0) {
            return;
        }

        $delay = min($cap, $base * (2 ** $attempt));
        usleep($delay * 1_000_000);
    }

    /**
     * @param  list<string>  $knownSecrets
     */
    private function failureResult(int $status, string $body, int $durationMs, int $retries, array $knownSecrets = []): HttpFetchResult
    {
        return new HttpFetchResult(
            success: false,
            status: $status,
            body: $body,
            durationMs: $durationMs,
            retries: $retries,
            errorMessage: $this->maskSecrets(sprintf('HTTP status %d', $status), $knownSecrets),
        );
    }

    private function maskUrlForLogging(string $url, ?string $user): string
    {
        if ($user === null || $user === '') {
            return $url;
        }

        $parts = parse_url($url);
        if (! $parts) {
            return $url;
        }

        $parts['user'] = $user;
        $parts['pass'] = '***';

        return $this->unparseUrl($parts);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function unparseUrl(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':'.$parts['pass'] : '';
        $pass = ($user || $pass) ? "$pass@" : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    /**
     * @param  list<string>  $knownSecrets
     */
    private function maskSecrets(string $message, array $knownSecrets = []): string
    {
        $result = $message;

        foreach ($knownSecrets as $secret) {
            if ($secret === '') {
                continue;
            }
            $quoted = preg_quote($secret, '/');
            $result = preg_replace('/'.$quoted.'/', '***:redacted:', $result);
        }

        $patterns = [
            // Basic auth (Basic base64) / Bearer tokens
            '/\b(?:Basic|Bearer|Token|OAuth)\s+[A-Za-z0-9\-._~+\/=]+/i' => static fn (array $m): string => Str::before($m[0], ' ').' ***',
            // Key=value forms with sensitive keys
            '/(?P<kw>(?:password|passwd|pwd|secret|api[_-]?key|token|access[_-]?token|auth|authorization)[\'"]?\s*[:=]\s*)(?P<val>[^\s;&,"\']+)/i' => static fn (array $m): string => sprintf('%s***', $m['kw']),
            // URL credentials: user:pass@host
            '/(?<=[\/:@.])[A-Za-z0-9\-._~%]+:[^\/\s@"<>]+@/' => static fn (array $m): string => '***:***@',
            // Header credential forms: header name in SECRET_HEADER_NAMES + ": value"
            '/(?P<name>'.implode('|', array_map('preg_quote', self::SECRET_HEADER_NAMES)).')(?P<delim>\s*:\s*)(?P<val>[^\r\n]+)/i' => static fn (array $m): string => sprintf('%s%s***', $m['name'], $m['delim']),
        ];

        foreach ($patterns as $regex => $replace) {
            $result = preg_replace_callback($regex, $replace, $result);
        }

        return $result;
    }
}
