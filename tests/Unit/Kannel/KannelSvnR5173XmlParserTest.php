<?php

use App\Services\Kannel\XmlStatusParser;

test('XML parser handles svn-r5173 nested sms/wdp/dlr blocks correctly', function () {
    $xml = '<?xml version="1.0"?>
<gateway>
<version>Kannel svn-r5173</version>
<status>running, uptime 0d 0h 14m 58s</status>
	<wdp>
		<received><total>0</total><queued>0</queued></received>
		<sent><total>0</total><queued>0</queued></sent>
	</wdp>
	<sms>
		<received><total>0</total><queued>0</queued></received>
		<sent><total>0</total><queued>1</queued></sent>
		<storesize>1</storesize>
		<inbound>0.00,0.00,0.00</inbound>
		<outbound>0.00,0.00,0.00</outbound>
		</sms>
	<dlr>
		<received><total>0</total></received>
		<sent><total>0</total></sent>
		<queued>0</queued>
	</dlr>
<smscs><count>1</count>
	<smsc>
		<name>FAKE:12345</name>
		<admin-id>unknown</admin-id>
		<id>unknown</id>
		<status>connecting</status>
		<failed>0</failed>
		<queued>0</queued>
		<sms>
			<received>5</received>
			<sent>7</sent>
			<inbound>0.00,0.00,0.00</inbound>
			<outbound>0.00,0.00,0.00</outbound>
		</sms>
	</smsc>
</smscs>
</gateway>';

    $parser = new XmlStatusParser;
    [$snapshot, $smscs] = $parser->parse($xml);

    expect($snapshot->uptimeSeconds)->toBe(14 * 60 + 58);
    expect($snapshot->moTotal)->toBe(0);
    expect($snapshot->mtTotal)->toBe(0);
    expect($snapshot->smsQueued)->toBe(1);
    expect($snapshot->storeSize)->toBe(1);
    expect($snapshot->dlrQueue)->toBe(0);

    expect($smscs)->toHaveCount(1);
    $s = $smscs[0];
    expect($s->smscName)->toBe('FAKE:12345');
    expect($s->smscId)->toBe('unknown');
    expect($s->state->value)->toBe('binding');
    expect($s->received)->toBe(5);
    expect($s->sent)->toBe(7);
    expect($s->moCount)->toBe(5);
    expect($s->mtCount)->toBe(7);
});

test('XML parser maps svn-r5173 SMSC status strings', function (string $rawStatus, string $expectedState) {
    $xml = '<?xml version="1.0"?>
<gateway>
<status>running, uptime 0d 0h 1m 0s</status>
	<sms>
		<received><total>0</total><queued>0</queued></received>
		<sent><total>0</total><queued>0</queued></sent>
	</sms>
<smscs><count>1</count>
	<smsc>
		<name>FAKE:12345</name>
		<id>unknown</id>
		<status>'.$rawStatus.'</status>
	</smsc>
</smscs>
</gateway>';

    [, $smscs] = (new XmlStatusParser)->parse($xml);

    expect($smscs[0]->state->value)->toBe($expectedState);
})->with([
    'online' => ['online 19s', 'connected'],
    'connecting' => ['connecting', 'binding'],
    're-connecting' => ['re-connecting', 'reconnecting'],
    'disconnected' => ['disconnected', 'disconnected'],
    'dead' => ['dead', 'disconnected'],
]);
