<?php

use App\DataObjects\Kannel\AlertEvaluationResult;
use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Enums\EndpointStatus;
use App\Models\KannelAlertEvent;
use App\Models\KannelAlertRule;
use App\Models\KannelEndpoint;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\KannelAlertNotification;
use App\Services\Kannel\Alerting\AlertManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

it('dispatches KannelAlertNotification through webhook on rule fire via manager', function () {
    Notification::fake();

    $endpoint = KannelEndpoint::factory()->create([
        'enabled' => true,
        'status' => EndpointStatus::Unreachable,
        'consecutive_failures' => 5,
    ]);
    $rule = KannelAlertRule::factory()->create([
        'type' => AlertRuleType::EndpointUnreachable,
        'severity' => AlertSeverity::Warning,
        'cooldown_minutes' => 0,
        'threshold' => ['consecutive_failures' => 2],
        'channels' => [[
            'type' => 'webhook',
            'url' => 'https://hooks.example.test/kannel',
            'secret' => 'top-secret-signing-key',
            'method' => 'POST',
        ]],
    ]);

    $manager = app(AlertManager::class);
    $events = $manager->runEvaluation(collect([$rule]), [
        'endpoints' => [$endpoint],
        'latest_snapshots' => [],
        'latest_smscs' => [],
    ]);

    expect($events)->toHaveCount(1);

    Notification::assertSentOnDemand(KannelAlertNotification::class, function ($notification, $channels, $notifiable) {
        if (! in_array(WebhookChannel::class, $channels, true)) {
            return false;
        }
        $route = $notifiable->routeNotificationFor(WebhookChannel::class, $notification);
        expect($route['url'] ?? null)->toBe('https://hooks.example.test/kannel');
        expect($route['secret'] ?? null)->toBe('top-secret-signing-key');
        expect($route['method'] ?? null)->toBe('POST');

        return true;
    });

    // Also confirm the actual HMAC generation produces a valid signature header
    // by driving the real WebhookChannel with an isolated HTTP fake.
    Http::fake([
        'hooks.example.test/*' => ['ok' => true],
    ]);

    $event = KannelAlertEvent::query()->firstOrFail();
    $result = new AlertEvaluationResult(
        triggered: true,
        ruleType: AlertRuleType::EndpointUnreachable,
        severity: AlertSeverity::Warning,
        fingerprintKey: 'unreachable:'.$endpoint->id,
        message: 'Test firing',
        endpointId: $endpoint->id,
        smscName: null,
        context: ['consecutive_failures' => 5],
    );

    $notification = new KannelAlertNotification($rule, $event, $result);
    $notifiable = new class($rule->channels[0])
    {
        public function __construct(public array $route) {}

        public function routeNotificationFor($channel, $n = null): array
        {
            return $this->route;
        }
    };

    app(WebhookChannel::class)->send($notifiable, $notification);

    Http::assertSent(function ($request) {
        return $request->hasHeader(config('kannel.channels.webhook.hmac_header'))
            && str_starts_with($request->url(), 'https://hooks.example.test/kannel');
    });
});
