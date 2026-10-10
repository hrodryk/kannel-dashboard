<?php

namespace App\Notifications\Channels;

use App\Notifications\KannelAlertNotification;
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
     * @return array{status:int, body:string}|null
     */
    public function send($notifiable, Notification $notification): ?array
    {
        if (! $notification instanceof KannelAlertNotification) {
            return null;
        }

        /** @var mixed $routeRaw */
        $routeRaw = $notifiable->routeNotificationFor(self::class, $notification);
        if (! is_array($routeRaw) || ! isset($routeRaw['url']) || ! is_string($routeRaw['url']) || $routeRaw['url'] === '') {
            return null;
        }

        /** @var array{url:string, secret?:?string, headers?:array<string, string>, method?:string} $route */
        $route = $routeRaw;

        $body = $notification->toWebhook($notifiable);
        $method = strtoupper((string) ($route['method'] ?? 'POST'));
        $routeHeaders = isset($route['headers']) && is_array($route['headers']) ? $route['headers'] : [];
        $headers = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $routeHeaders);

        $secretRaw = $route['secret'] ?? null;
        $secret = is_string($secretRaw) && $secretRaw !== '' ? $secretRaw : null;
        if ($secret !== null) {
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
