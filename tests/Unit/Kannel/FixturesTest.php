<?php

test('3smsc XML fixture loads and parses as SimpleXML', function () {
    $path = base_path('tests/Fixtures/Kannel/status_3smscs.xml');
    $contents = file_get_contents($path);

    expect($contents)->not->toBeEmpty();
    $xml = simplexml_load_string($contents);
    expect($xml)->not->toBeFalse();
    expect((string) $xml->status)->toBe('running');
    expect($xml->smscs->smsc)->toHaveCount(3);
    expect((int) $xml->uptime)->toBeGreaterThan(1000);
});

test('single smsc fixture parses', function () {
    $path = base_path('tests/Fixtures/Kannel/status_single_smsc.xml');
    $xml = simplexml_load_string(file_get_contents($path));
    expect($xml)->not->toBeFalse();
    expect((string) $xml->smscs->smsc[0]->name)->toBe('SMSC_PRIMARY');
});

test('2smsc JSON fixture parses', function () {
    $path = base_path('tests/Fixtures/Kannel/status_2smscs.json');
    $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect($data['gateway']['smscs'])->toHaveCount(2);
    expect((int) $data['gateway']['uptime'])->toBeGreaterThan(1000);
});
