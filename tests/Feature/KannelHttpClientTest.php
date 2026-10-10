<?php

use App\DataObjects\Kannel\HttpFetchResult;
use App\Models\KannelEndpoint;
use App\Services\Kannel\HttpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('HttpClient succeeds and reports zero retries on valid XML response', function () {
    $fixture = file_get_contents(base_path('tests/Fixtures/Kannel/status_3smscs.xml'));
    Http::fake([
        '*' => Http::response($fixture, 200, ['Content-Type' => 'application/xml']),
    ]);

    $endpoint = KannelEndpoint::factory()->create([
        'url' => 'http://kannel.test:13000',
        'admin_user' => 'admin',
        'admin_password' => 'secret-pass',
    ]);

    $client = $this->app->make(HttpClient::class);
    $result = $client->fetchStatus($endpoint, 'xml');

    expect($result)->toBeInstanceOf(HttpFetchResult::class);
    expect($result->success)->toBeTrue();
    expect($result->status)->toBe(200);
    expect($result->retries)->toBe(0);
    expect(strlen($result->body))->toBeGreaterThan(500);

    Http::assertSentCount(1);
    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'status.xml')
            && $request->hasHeader('Authorization');
    });
});

test('HttpClient retries on 5xx with backoff and finally succeeds on retry', function () {
    $fixture = file_get_contents(base_path('tests/Fixtures/Kannel/status_single_smsc.xml'));
    Http::fakeSequence()
        ->pushStatus(500)
        ->push($fixture, 200, ['Content-Type' => 'application/xml']);

    $endpoint = KannelEndpoint::factory()->create();
    $client = $this->app->make(HttpClient::class);
    config(['kannel.http.retry_max_attempts' => 4]);
    $result = $client->fetchStatus($endpoint, 'xml');

    expect($result->success)->toBeTrue();
    expect($result->retries)->toBe(1);
    Http::assertSentCount(2);
});

test('HttpClient reports failure after exhausting retries on connection errors', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    $endpoint = KannelEndpoint::factory()->create();
    config(['kannel.http.retry_max_attempts' => 1]);
    $client = $this->app->make(HttpClient::class);
    $result = $client->fetchStatus($endpoint, 'xml');

    expect($result->success)->toBeFalse();
    expect($result->status)->toBe(500);
    expect($result->errorMessage)->not->toBeNull();
    Http::assertSentCount(1);
});

test('HttpClient logs sanitized URL with password masked', function () {
    $fixture = file_get_contents(base_path('tests/Fixtures/Kannel/status_3smscs.xml'));
    Http::fake([
        '*' => Http::response($fixture, 200, ['Content-Type' => 'application/xml']),
    ]);

    $logMessages = [];
    $logger = new class($logMessages)
    {
        public function __construct(public array &$logs) {}

        public function __call(string $name, array $args): self
        {
            $this->logs[] = ['level' => $name, 'message' => (string) ($args[0] ?? ''), 'context' => (array) ($args[1] ?? [])];

            return $this;
        }
    };
    $stack = new class($logger)
    {
        public function __construct(public $inner) {}

        public function channel($ch = null): self
        {
            return $this;
        }

        public function __call(string $name, array $args): void
        {
            $this->inner->__call($name, $args);
        }
    };
    Log::swap($stack);

    $endpoint = KannelEndpoint::factory()->create([
        'url' => 'http://kannel.test:13000',
        'admin_user' => 'admin_user',
        'admin_password' => 'SENSITIVE_PASSWORD_123',
    ]);

    $client = $this->app->make(HttpClient::class);
    $client->fetchStatus($endpoint, 'xml');

    $found = false;
    foreach ($logMessages as $entry) {
        $encoded = json_encode($entry, JSON_THROW_ON_ERROR);
        if (
            str_contains($entry['message'] ?? '', 'kannel.fetch.success')
            && str_contains($encoded, 'admin_user')
            && str_contains($encoded, '***')
            && ! str_contains($encoded, 'SENSITIVE_PASSWORD_123')
        ) {
            $found = true;
            break;
        }
    }

    expect($found)->toBeTrue();
});

test('HttpClient fetchStoreStatus uses store-status.xml URL instead of status.xml', function () {
    $storeFixture = file_get_contents(base_path('tests/Fixtures/Kannel/store_status_single.xml'));
    Http::fake([
        '*' => Http::response($storeFixture, 200, ['Content-Type' => 'application/xml']),
    ]);

    $endpoint = KannelEndpoint::factory()->create([
        'url' => 'http://kannel.test:13000',
        'admin_user' => 'admin',
        'admin_password' => 'secret',
    ]);

    $client = $this->app->make(HttpClient::class);
    $result = $client->fetchStoreStatus($endpoint);

    expect($result)->toBeInstanceOf(HttpFetchResult::class);
    expect($result->success)->toBeTrue();
    expect($result->status)->toBe(200);

    Http::assertSentCount(1);
    Http::assertSent(function ($request): bool {
        $hasStoreStatus = str_contains($request->url(), 'store-status.xml');
        $hasStatusXml = str_contains($request->url(), '/status.xml') && ! str_contains($request->url(), 'store-status.xml');

        return $hasStoreStatus && ! $hasStatusXml;
    });
});
