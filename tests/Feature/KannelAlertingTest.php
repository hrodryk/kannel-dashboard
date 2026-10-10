<?php

use App\DataObjects\Kannel\AlertEvaluationResult;
use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Enums\SMSCState;
use App\Models\KannelAlertEvent;
use App\Models\KannelAlertRule;
use App\Models\KannelEndpoint;
use App\Models\KannelMetricSnapshot;
use App\Models\KannelSmscMetric;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\KannelAlertNotification;
use App\Services\Kannel\Alerting\AlertManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('SMSC Down rule triggers exactly once respecting cooldown then fires again after window', function () {
    Notification::fake();

    $endpoint = KannelEndpoint::factory()->create();
    $snapshot = KannelMetricSnapshot::factory()->create(['endpoint_id' => $endpoint->id]);
    $smsc = KannelSmscMetric::factory()->create([
        'snapshot_id' => $snapshot->id,
        'endpoint_id' => $endpoint->id,
        'smsc_name' => 'SMSC_X',
        'smsc_state' => SMSCState::Disconnected,
    ]);

    $rule = KannelAlertRule::factory()->create([
        'type' => AlertRuleType::SmscDown,
        'severity' => AlertSeverity::Critical,
        'cooldown_minutes' => 15,
        'channels' => [
            ['type' => 'mail', 'emails' => ['ops@example.test']],
        ],
    ]);

    $manager = $this->app->make(AlertManager::class);

    $context = [
        'endpoints' => [$endpoint],
        'latest_snapshots' => [$snapshot],
        'latest_smscs' => [$smsc],
    ];

    $events = $manager->runEvaluation(collect([$rule]), $context);
    expect($events)->toHaveCount(1);
    expect($events[0]->state)->toBe('firing');

    // Re-evaluation inside the cooldown window must NOT record a second event
    // (the manager updates the existing event rather than returning a new one).
    $events2 = $manager->runEvaluation(collect([$rule]), $context);
    expect($events2)->toHaveCount(0);

    Notification::assertSentTimes(KannelAlertNotification::class, 1);

    $this->travel(16)->minutes();
    $events3 = $manager->runEvaluation(collect([$rule]), $context);
    expect($events3)->toHaveCount(1);
    // After the cooldown elapses, a second notification should be dispatched.
    Notification::assertSentTimes(KannelAlertNotification::class, 2);
});

test('QueueExceeded rule triggers above outbound_queue threshold', function () {
    $endpoint = KannelEndpoint::factory()->create();
    $snapshot = KannelMetricSnapshot::factory()->create([
        'endpoint_id' => $endpoint->id,
        'outbound_queue' => 2500,
    ]);

    $rule = KannelAlertRule::factory()->queueExceeded()->create([
        'endpoint_id' => $endpoint->id,
        'threshold' => ['outbound_queue' => 1000],
    ]);

    $manager = $this->app->make(AlertManager::class);
    $events = $manager->runEvaluation(collect([$rule]), [
        'endpoints' => [$endpoint],
        'latest_snapshots' => [$snapshot],
        'latest_smscs' => [],
    ]);

    expect($events)->toHaveCount(1);
    expect($events[0]->message)->toContain('threshold 1000');
});

test('EndpointUnreachable rule triggers after consecutive_failures threshold', function () {
    $endpoint = KannelEndpoint::factory()->unreachable()->create();
    $rule = KannelAlertRule::factory()->create([
        'type' => AlertRuleType::EndpointUnreachable,
        'severity' => AlertSeverity::Warning,
        'threshold' => ['consecutive_failures' => 3],
        'channels' => [['type' => 'mail', 'emails' => ['ops@example.test']]],
    ]);

    $manager = $this->app->make(AlertManager::class);
    $events = $manager->runEvaluation(collect([$rule]), [
        'endpoints' => [$endpoint],
        'latest_snapshots' => [],
        'latest_smscs' => [],
    ]);

    expect($events)->toHaveCount(1);
    $e = KannelAlertEvent::first();
    expect($e->endpoint_id)->toBe($endpoint->id);
    expect($e->state)->toBe('firing');
});

test('Webhook channel POSTs JSON with HMAC signature header when secret given', function () {
    Notification::fake();
    Http::fake([
        'hooks.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    $endpoint = KannelEndpoint::factory()->unreachable()->create();
    $rule = KannelAlertRule::factory()->create([
        'type' => AlertRuleType::EndpointUnreachable,
        'cooldown_minutes' => 0,
        'threshold' => ['consecutive_failures' => 2],
        'channels' => [
            [
                'type' => 'webhook',
                'url' => 'https://hooks.example.test/kannel',
                'secret' => 'top-secret-signing-key',
                'method' => 'POST',
            ],
        ],
    ]);

    $manager = $this->app->make(AlertManager::class);
    $events = $manager->runEvaluation(collect([$rule]), [
        'endpoints' => [$endpoint],
        'latest_snapshots' => [],
        'latest_smscs' => [],
    ]);

    expect($events)->toHaveCount(1);

    // Confirm the manager routes the notification through the webhook channel
    // with the decrypted secret + configured URL/method intact.
    Notification::assertSentOnDemand(KannelAlertNotification::class, function ($notification, $channels, $notifiable) {
        if (! in_array(WebhookChannel::class, $channels, true)) {
            return false;
        }
        $route = $notifiable->routeNotificationFor(WebhookChannel::class, $notification);

        return ($route['url'] ?? null) === 'https://hooks.example.test/kannel'
            && ($route['secret'] ?? null) === 'top-secret-signing-key'
            && ($route['method'] ?? null) === 'POST';
    });

    // Independently exercise the real WebhookChannel to confirm the HMAC
    // header is actually attached to the outgoing HTTP request.
    $event = KannelAlertEvent::query()->firstOrFail();
    $result = new AlertEvaluationResult(
        triggered: true,
        ruleType: AlertRuleType::EndpointUnreachable,
        severity: AlertSeverity::Warning,
        fingerprintKey: 'unreachable:'.$endpoint->id,
        message: 'Webhook firing',
        endpointId: $endpoint->id,
        smscName: null,
        context: ['consecutive_failures' => 5],
    );
    $note = new KannelAlertNotification($rule, $event, $result);
    $notifiable = new class($rule->channels[0])
    {
        public function __construct(public array $route) {}

        public function routeNotificationFor($channel, $n = null): array
        {
            return $this->route;
        }
    };

    app(WebhookChannel::class)->send($notifiable, $note);

    Http::assertSent(function ($request) {
        return $request->hasHeader(config('kannel.channels.webhook.hmac_header'))
            && str_starts_with($request->url(), 'https://hooks.example.test/kannel');
    });
});
