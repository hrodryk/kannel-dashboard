<?php

use App\Services\Kannel\JsonStatusParser;
use App\Services\Kannel\XmlStatusParser;

test('XML parser extracts expected 3 SMSCs with correct counters', function () {
    $xml = file_get_contents(base_path('tests/Fixtures/Kannel/status_3smscs.xml'));
    $parser = new XmlStatusParser;
    [$snapshot, $smscs] = $parser->parse($xml);

    expect($snapshot->uptimeSeconds)->toBe(86450);
    expect($snapshot->moTotal)->toBe(120560);
    expect($snapshot->mtTotal)->toBe(298340);
    expect($snapshot->inboundQueue)->toBe(127);
    expect($snapshot->outboundQueue)->toBe(534);
    expect($snapshot->dlrQueue)->toBe(42);
    expect($snapshot->failedCount)->toBe(342);
    expect($snapshot->boxId)->toBe('kannel-main-01');
    expect($snapshot->errors)->toHaveCount(3);

    expect($smscs)->toHaveCount(3);

    [$a, $b, $c] = $smscs;

    expect($a->smscName)->toBe('SMSC_A');
    expect($a->state->value)->toBe('connected');
    expect($a->moCount)->toBe(50320);
    expect($a->mtCount)->toBe(149200);
    expect($a->latencyMs)->toBe(18);

    expect($b->smscName)->toBe('SMSC_B');
    expect($b->state->value)->toBe('disconnected');
    expect($b->failed)->toBe(194);

    expect($c->state->value)->toBe('binding');
    expect($c->latencyMs)->toBe(240);
});

test('JSON parser extracts expected counters and SMSCs', function () {
    $json = file_get_contents(base_path('tests/Fixtures/Kannel/status_2smscs.json'));
    $parser = new JsonStatusParser;
    [$snapshot, $smscs] = $parser->parse($json);

    expect($snapshot->boxId)->toBe('kannel-main-01');
    expect($snapshot->moTotal)->toBe(120560);
    expect($smscs)->toHaveCount(2);
    expect($smscs[0]->state->value)->toBe('connected');
    expect($smscs[1]->state->value)->toBe('disconnected');
    expect($snapshot->errors[0]['code'] ?? null)->toBe('0x0000000B');
});

test('XML parser handles empty SMSC and errors nodes gracefully', function () {
    $xml = file_get_contents(base_path('tests/Fixtures/Kannel/status_single_smsc.xml'));
    $parser = new XmlStatusParser;
    [$snapshot, $smscs] = $parser->parse($xml);

    expect($snapshot->errors)->toBeArray()->toHaveCount(0);
    expect($smscs)->toHaveCount(1);
    expect($smscs[0]->smscName)->toBe('SMSC_PRIMARY');
    expect($smscs[0]->latencyMs)->toBe(22);
});

test('parsers fail fast on invalid payload', function () {
    $parser = new XmlStatusParser;
    $parser->parse('not xml at all');
})->throws(RuntimeException::class);

test('JsonStatusParser fails on invalid JSON', function () {
    (new JsonStatusParser)->parse('not json');
})->throws(JsonException::class);
