<?php

use App\Models\KannelEndpoint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

test('endpoint stores admin password encrypted and hides it from array', function () {
    $endpoint = KannelEndpoint::factory()->create([
        'admin_password' => 'super-secret-kannel-admin',
    ]);

    $stored = DB::table('kannel_endpoints')
        ->where('id', $endpoint->id)
        ->value('admin_password');

    expect($stored)->not->toBe('super-secret-kannel-admin')
        ->and(Crypt::decryptString($stored))->toBe('super-secret-kannel-admin');

    $asArray = $endpoint->toArray();
    expect($asArray)->not->toHaveKey('admin_password');
    expect($asArray)->not->toHaveKey('admin_api_key_value');
});

test('endpoint admin password getter decrypts correctly', function () {
    $endpoint = KannelEndpoint::factory()->create([
        'admin_password' => 'correct-horse-battery-staple',
    ]);

    $endpoint->refresh();
    expect($endpoint->admin_password)->toBe('correct-horse-battery-staple');
});

test('endpoint url builder respects xml vs json formats', function () {
    $endpoint = new KannelEndpoint(['url' => 'https://kannel.test:13000/']);
    expect($endpoint->statusUrl('xml'))->toBe('https://kannel.test:13000/status.xml');
    expect($endpoint->statusUrl('json'))->toBe('https://kannel.test:13000/status.json');
});
