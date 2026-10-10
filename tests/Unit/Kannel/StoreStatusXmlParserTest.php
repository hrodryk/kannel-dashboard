<?php

use App\Services\Kannel\StoreStatusXmlParser;

test('store-status XML parser decodes all fields from user sample', function () {
    $payload = '<?xml version="1.0"?>
<gateway>
	<message>
	<id>b8434592-d31f-4383-a88e-ff402cb18c04</id>
	<type>MT-PUSH</type>
	<time>2026-09-05 14:40:58</time>
	<sender>fake</sender>
	<receiver>0123456</receiver>
	<smsc-id></smsc-id>
	<box-id></box-id>
	<flags>-1:0:-1:-1:-1</flags>
	<udh-data></udh-data>
	<msg-data>Hello+world</msg-data>
	</message>
</gateway>';

    $parser = new StoreStatusXmlParser;
    $rows = $parser->parse($payload);

    expect($rows)->toHaveCount(1);
    $msg = $rows[0];
    expect($msg->id)->toBe('b8434592-d31f-4383-a88e-ff402cb18c04');
    expect($msg->type)->toBe('MT-PUSH');
    expect($msg->sender)->toBe('fake');
    expect($msg->receiver)->toBe('0123456');
    expect($msg->smscId)->toBeNull();
    expect($msg->boxId)->toBeNull();
    expect($msg->flags)->toBe('-1:0:-1:-1:-1');
    expect($msg->udhData)->toBeNull();
    expect($msg->msgData)->toBe('Hello+world');
    expect($msg->msgDataDecoded())->toBe('Hello world');
    expect($msg->time)->toBeInstanceOf(DateTimeImmutable::class);
    expect($msg->time?->format('Y-m-d H:i:s'))->toBe('2026-09-05 14:40:58');
});

test('store-status parser handles multiple messages and empty gracefully', function () {
    $payload = '<?xml version="1.0"?>
<gateway>
	<message>
	<id>m1</id><type>MO-DELIVER</type><time>Sat Sep  5 14:42:00 2026</time>
	<sender>+11</sender><receiver>777</receiver><flags>0</flags><msg-data>Hi%21</msg-data>
	</message>
	<message>
	<id>m2</id><type>MT-PUSH</type><time>1725548520</time>
	<sender></sender><receiver>1</receiver><smsc-id>FAKE</smsc-id><msg-data></msg-data>
	</message>
</gateway>';

    $rows = (new StoreStatusXmlParser)->parse($payload);
    expect($rows)->toHaveCount(2);

    [$m1, $m2] = $rows;
    expect($m1->type)->toBe('MO-DELIVER');
    expect($m1->receiver)->toBe('777');
    expect($m1->msgDataDecoded())->toBe('Hi!');
    expect($m1->time)->not->toBeNull();

    expect($m2->type)->toBe('MT-PUSH');
    expect($m2->sender)->toBeNull();
    expect($m2->smscId)->toBe('FAKE');
    expect($m2->msgDataDecoded())->toBeNull();

    $empty = (new StoreStatusXmlParser)->parse('<gateway></gateway>');
    expect($empty)->toHaveCount(0);
});
