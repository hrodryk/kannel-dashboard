<?php

namespace App\Notifications\Channels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Notifications\Notification;

class WebhookChannel
{
    public function __construct(
        protected Http $http,
    ) {}

    /**
     * @param  mixed  $notifiable
     */
    public function send($notifiable, Notification $notification): ?array
    {
        /** @var array{url:string, secret?:?string, headers?:array<string, string>, method?:string} $route */
        $route = $notifiable->routeNotificationFor(self::class, $notification);

        if (! is_array($route) || ! isset($route['url'])) {
            return null;
        }

        $body = $notification->toWebhook($notifiable);
        $method = strtoupper((string) ($route['method'] ?? 'POST'));
        $headers = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], is_array($route['headers'] ?? null) ? $route['headers'] : []);

        $secret = isset($route['secret']) && is_string($route['secret']) ? $route['secret'] : null;
        if ($secret !== null && $secret !== '') {
            $header = config('kannel.channels.webhook.hmac_header', 'X-Kannel-Signature');
            $algo = config('kannel.channels.webhook.hmac_algo', 'sha256');
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload !== false) {
                $headers[$header] = hash_hmac($algo, $payload, $secret);
            }
        }

        $timeout = (int) config('kannel.channels.webhook.timeout_seconds', 10);

        try {
            $response = $this->http
                ->timeout($timeout)
                ->withHeaders($headers)
                ->withOptions(['verify' => true])
                ->send($method, $route['url'], ['json' => $body]);

            return [
                'status' => $response->status(),
                'body' => $response->body(),
            ];
        } catch (ConnectionException $e) {
            return [
                'status' => 0,
                'body' => $e->getMessage(),
            ];
        }
    }
}
