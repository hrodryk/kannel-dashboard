<?php

use App\Models\KannelEndpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->userWith = User::factory()->create(['manage_kannel' => true]);
    $this->userWithout = User::factory()->create(['manage_kannel' => false]);
});

test('dashboard is protected by auth middleware', function () {
    get(route('dashboard'))
        ->assertRedirectToRoute('login');
});

test('dashboard renders with kpi deferred props for authorized user', function () {
    actingAs($this->userWith)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('can_manage_kannel', true)
            ->has('endpoints')
            ->has('poll_interval_seconds')
            ->etc()
        );
});

test('settings kannel endpoints page denies user without manage_kannel', function () {
    actingAs($this->userWithout)->get(route('kannel.endpoints.index'))
        ->assertForbidden();
});

test('settings kannel endpoints page allows user with manage_kannel gate', function () {
    actingAs($this->userWith)->get(route('kannel.endpoints.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('kannel/Endpoints')
            ->has('endpoints')
            ->has('defaults')
            ->etc()
        );
});

test('store endpoint validates and encrypts password', function () {
    actingAs($this->userWith)->post(route('kannel.endpoints.store'), [
        'name' => 'Test Endpoint',
        'admin_url' => 'https://kannel.internal:13000/status.xml',
        'preferred_format' => 'xml',
        'admin_username' => 'kanneluser',
        'admin_password' => 'secret-pass-2',
        'enabled' => true,
    ])->assertRedirect(route('kannel.endpoints.index'));

    $this->assertDatabaseHas('kannel_endpoints', [
        'name' => 'Test Endpoint',
        'admin_user' => 'kanneluser',
        'url' => 'https://kannel.internal:13000',
        'status' => 'idle',
    ]);

    $stored = KannelEndpoint::query()->firstOrFail();
    expect($stored->getRawOriginal('admin_password'))->not->toBe('secret-pass-2');
    expect($stored->admin_password)->toBe('secret-pass-2');
    expect($stored->toArray())->not->toHaveKey('admin_password');
    expect($stored->admin_username)->toBe('kanneluser');
    expect($stored->admin_url)->toBe('https://kannel.internal:13000');
    expect($stored->status->value)->toBe('idle');
});

test('store endpoint rejects non-http/https url', function () {
    actingAs($this->userWith)->post(route('kannel.endpoints.store'), [
        'name' => 'Bad',
        'admin_url' => 'ftp://nope.test',
        'preferred_format' => 'auto',
    ])->assertSessionHasErrors(['admin_url']);
});
