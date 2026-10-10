<?php

use App\DataObjects\Kannel\AlertEvaluationResult;
use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Models\KannelAlertEvent;
use App\Models\KannelAlertRule;
use App\Models\KannelEndpoint;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\KannelAlertNotification;
use Illuminate\Support\Facades\Http;

it('sends an HTTP POST with HMAC header via WebhookChannel directly', function () {
    Http::fake([
        'hooks.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    $endpoint = KannelEndpoint::factory()->unreachable()->create();
    $rule = KannelAlertRule::factory()->create([
        'type' => AlertRuleType::EndpointUnreachable,
        'cooldown_minutes' => 0,
        'threshold' => ['consecutive_failures' => 2],
        'channels' => [[
            'type' => 'webhook',
            'url' => 'https://hooks.example.test/kannel',
            'secret' => 'top-secret-signing-key',
            'method' => 'POST',
        ]],
    ]);

    $rule->refresh();
    expect($rule->channels[0]['secret'] ?? null)->toBe('top-secret-signing-key');

    $result = new AlertEvaluationResult(
        triggered: true,
        ruleType: AlertRuleType::EndpointUnreachable,
        severity: AlertSeverity::Warning,
        fingerprintKey: 'unreachable:'.$endpoint->id,
        message: 'Endpoint is unreachable',
        endpointId: $endpoint->id,
        smscName: null,
        context: ['consecutive_failures' => 5],
    );

    $event = KannelAlertEvent::factory()->create([
        'rule_id' => $rule->id,
        'endpoint_id' => $endpoint->id,
        'message' => 'Endpoint is unreachable',
    ]);

    $notification = new KannelAlertNotification($rule, $event, $result);
    $notifiable = new class
    {
        public function routeNotificationFor($channel, $notification = null): array
        {
            $rule = KannelAlertRule::query()->latest()->first();
            $channel = $rule->channels[0] ?? [];

            return [
                'url' => $channel['url'],
                'secret' => $channel['secret'] ?? null,
                'headers' => $channel['headers'] ?? [],
                'method' => $channel['method'] ?? 'POST',
            ];
        }
    };

    $channel = app(WebhookChannel::class);
    $channel->send($notifiable, $notification);

    Http::assertSent(function ($request) {
        return $request->hasHeader(config('kannel.channels.webhook.hmac_header'))
            && str_starts_with($request->url(), 'https://hooks.example.test/kannel');
    });
});
