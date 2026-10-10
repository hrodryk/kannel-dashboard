<?php

use App\DataObjects\Kannel\AlertEvaluationResult;
use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Models\KannelAlertEvent;
use App\Models\KannelAlertRule;
use App\Models\KannelEndpoint;
use App\Models\User;
use App\Notifications\KannelAlertNotification;
use App\Services\Kannel\HttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

const TEST_SECRET_PASSWORDS = [
    'super-secret-admin-password-2024',
    'topsecret!kannel*key',
    'hunter2-password',
];

beforeEach(function () {
    KannelEndpoint::truncate();
});

test('admin password never appears in kannel log when HTTP request fails', function () {
    $pass = TEST_SECRET_PASSWORDS[0];
    $endpoint = KannelEndpoint::factory()->create([
        'admin_url' => 'http://doesnotexist.example.test:9/kannel',
        'admin_username' => 'admin',
        'admin_password' => $pass,
    ]);

    $logs = [];
    Log::swap(new class($logs) extends Logger
    {
        public function __construct(private array &$logsRef)
        {
            $this->logsRef = &$logsRef;
            // no-op parent driver constructor
        }

        public function emergency($message, array $context = []): void
        {
            $this->logsRef[] = ['level' => 'emergency', 'message' => $message, 'context' => $context];
        }

        public function alert($message, array $context = []): void
        {
            $this->logsRef[] = ['level' => 'alert', 'message' => $message, 'context' => $context];
        }

        public function critical($message, array $context = []): void
        {
            $this->logsRef[] = ['level' => 'critical', 'message' => $message, 'context' => $context];
        }

        public function error($message, array $context = []): void
        {
            $this->logsRef[] = ['level' => 'error', 'message' => $message, 'context' => $context];
        }

        public function warning($message, array $context = []): void
        {
            $this->logsRef[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
        }

        public function notice($message, array $context = []): void
        {
            $this->logsRef[] = ['level' => 'notice', 'message' => $message, 'context' => $context];
        }

        public function info($message, array $context = []): void
        {
            $this->logsRef[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        }

        public function debug($message, array $context = []): void
        {
            $this->logsRef[] = ['level' => 'debug', 'message' => $message, 'context' => $context];
        }

        public function log($level, $message, array $context = []): void
        {
            $this->logsRef[] = ['level' => (string) $level, 'message' => $message, 'context' => $context];
        }

        public function channel($channel = null)
        {
            return $this;
        }
    });

    try {
        $client = App::make(HttpClient::class);
        $client->fetchStatus($endpoint, 'xml');
    } catch (Throwable) {
    }

    $encoded = json_encode($logs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $this->assertNotFalse($encoded);
    expect($encoded)->not->toContain($pass);
});

test('stored endpoint password is encrypted and toArray never leaks it', function () {
    $pass = TEST_SECRET_PASSWORDS[1];
    $endpoint = KannelEndpoint::factory()->create([
        'admin_password' => $pass,
    ]);

    $raw = $endpoint->getRawOriginal('admin_password');
    expect($raw)->not->toBeNull();
    expect($raw)->not->toBe($pass);
    expect($endpoint->admin_password)->toBe($pass);

    $arr = $endpoint->toArray();
    expect($arr)->not->toHaveKey('admin_password');
    expect($arr)->not->toHaveKey('admin_api_key_value');

    $serialized = json_encode($endpoint);
    $this->assertNotFalse($serialized);
    expect($serialized)->not->toContain($pass);
});

test('alert rule channel webhook secret encrypted at rest', function () {
    $rule = KannelAlertRule::factory()->create([
        'channels' => [
            ['type' => 'webhook', 'url' => 'https://example.test/hooks', 'secret' => 'super-secret-webhook-key-1234'],
        ],
    ]);

    $raw = $rule->getRawOriginal('channels');
    $decoded = json_decode((string) $raw, true);
    $storedSecret = $decoded[0]['secret'] ?? null;

    expect($storedSecret)->not->toBeNull();
    expect($storedSecret)->not->toBe('super-secret-webhook-key-1234');
    expect($rule->channels[0]['secret'] ?? null)->toBe('super-secret-webhook-key-1234');
});

test('inertia endpoint index props do not expose admin password', function () {
    $user = User::factory()->create(['manage_kannel' => true]);
    KannelEndpoint::factory()->create([
        'name' => 'Top Secret Endpoint',
        'admin_url' => 'http://10.0.0.1:13000',
        'admin_username' => 'root',
        'admin_password' => TEST_SECRET_PASSWORDS[2],
        'admin_api_key_value' => 'secret-header-key-value',
    ]);

    $res = $this->actingAs($user)->get(route('kannel.endpoints.index'));
    $res->assertOk();

    $page = $res->viewData('page');
    $serialized = json_encode($page);
    $this->assertNotFalse($serialized);
    expect($serialized)->not->toContain(TEST_SECRET_PASSWORDS[2]);
    expect($serialized)->not->toContain('secret-header-key-value');
});

test('kannel alert notification toWebhook does not include raw password or key', function () {
    $rule = KannelAlertRule::factory()->queueExceeded()->create([
        'channels' => [['type' => 'mail', 'emails' => ['x@example.test']]],
    ]);
    $endpoint = KannelEndpoint::factory()->create(['admin_password' => TEST_SECRET_PASSWORDS[0]]);
    $event = KannelAlertEvent::factory()->create([
        'rule_id' => $rule->id,
        'endpoint_id' => $endpoint->id,
        'message' => 'Test firing',
    ]);
    $ctx = new AlertEvaluationResult(
        triggered: true,
        ruleType: AlertRuleType::QueueExceeded,
        severity: AlertSeverity::Warning,
        fingerprintKey: 'q:1',
        message: 'Test firing',
        endpointId: $endpoint->id,
        smscName: null,
        context: ['endpoint_id' => $endpoint->id, 'outbound_queue' => 1200],
    );

    $note = new KannelAlertNotification($rule, $event, $ctx);
    $payload = $note->toWebhook(new class
    {
        public function routeNotificationFor(): ?string
        {
            return null;
        }
    });

    $serialized = json_encode($payload);
    $this->assertNotFalse($serialized);
    expect($serialized)->not->toContain(TEST_SECRET_PASSWORDS[0]);
});
