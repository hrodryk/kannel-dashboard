<?php

use App\Enums\AlertChannelType;
use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Enums\EndpointStatus;
use App\Enums\SMSCState;

test('SMSCState enumerates expected cases', function () {
    $cases = SMSCState::cases();

    expect($cases)->toHaveCount(6);
    expect(SMSCState::Connected->isOperational())->toBeTrue();
    expect(SMSCState::Disconnected->isProblematic())->toBeTrue();
    expect(SMSCState::Connected->label())->toBe('Connected');
});

test('AlertRuleType has four monitor rule types', function () {
    expect(AlertRuleType::cases())->toHaveCount(4);
    expect(AlertRuleType::SmscDown->value)->toBe('smsc_down');
    expect(AlertRuleType::ThroughputDrop->label())->toContain('Baseline');
});

test('AlertSeverity has ordered info/warning/critical', function () {
    expect(AlertSeverity::Info->order())->toBe(0);
    expect(AlertSeverity::Warning->order())->toBe(1);
    expect(AlertSeverity::Critical->order())->toBe(2);
});

test('AlertChannelType includes mail/slack/webhook', function () {
    $values = array_map(fn ($c) => $c->value, AlertChannelType::cases());
    sort($values);
    expect($values)->toBe(['mail', 'slack', 'webhook']);
});

test('EndpointStatus has five expected states', function () {
    expect(EndpointStatus::cases())->toHaveCount(5);
    expect(EndpointStatus::Healthy->label())->toBe('Healthy');
    expect(EndpointStatus::Idle->label())->toBe('Idle');
});
