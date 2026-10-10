<?php

use App\Models\KannelEndpoint;

beforeEach(function () {
    $this->withoutExceptionHandling();
});

test('KannelEndpoint admin_url alias strips status.xml from base URL', function () {
    $ep = new KannelEndpoint;
    $ep->fill([
        'name' => 'Tinker EP',
        'admin_url' => 'https://kannel.example.com:13000/status.xml',
        'admin_username' => 'tuser',
        'admin_password' => 'secret',
    ]);

    expect($ep->url)->toBe('https://kannel.example.com:13000');
    expect($ep->admin_url)->toBe('https://kannel.example.com:13000');
    expect($ep->admin_user)->toBe('tuser');
    expect($ep->admin_username)->toBe('tuser');
    expect($ep->statusUrl())->toBe('https://kannel.example.com:13000/status.xml');
    expect($ep->storeStatusUrl())->toBe('https://kannel.example.com:13000/store-status.xml');
});

test('KannelEndpoint admin_url alias strips store-status.xml from base URL', function () {
    $ep = new KannelEndpoint;
    $ep->fill(['name' => 'A', 'admin_url' => 'http://10.0.0.2:13000/store-status.xml']);
    expect($ep->url)->toBe('http://10.0.0.2:13000');
    expect($ep->statusUrl('xml'))->toBe('http://10.0.0.2:13000/status.xml');
    expect($ep->storeStatusUrl())->toBe('http://10.0.0.2:13000/store-status.xml');
});

test('KannelEndpoint admin_url keeps plain hostname+port URLs intact', function () {
    $ep = new KannelEndpoint;
    $ep->fill(['name' => 'A', 'admin_url' => 'http://10.0.0.2:13000/']);
    expect($ep->url)->toBe('http://10.0.0.2:13000');
    expect($ep->storeStatusUrl())->toBe('http://10.0.0.2:13000/store-status.xml');

    $ep2 = new KannelEndpoint;
    $ep2->fill(['name' => 'B', 'admin_url' => 'http://10.0.0.2:13000']);
    expect($ep2->url)->toBe('http://10.0.0.2:13000');
    expect($ep2->statusUrl('json'))->toBe('http://10.0.0.2:13000/status.json');
});
