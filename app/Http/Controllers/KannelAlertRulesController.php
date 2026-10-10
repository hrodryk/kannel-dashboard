<?php

namespace App\Http\Controllers;

use App\Enums\AlertChannelType;
use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Models\KannelAlertRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class KannelAlertRulesController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('kannel/AlertRules', [
            'rules' => KannelAlertRule::query()
                ->with('endpoint:id,name')
                ->orderBy('name')
                ->paginate($request->integer('per_page', 25)),
            'rule_types' => array_map(fn (AlertRuleType $t): array => ['value' => $t->value, 'label' => $t->label()], AlertRuleType::cases()),
            'severities' => array_map(fn (AlertSeverity $s): array => ['value' => $s->value, 'label' => $s->label()], AlertSeverity::cases()),
            'channel_types' => array_map(fn (AlertChannelType $c): array => ['value' => $c->value, 'label' => $c->label()], AlertChannelType::cases()),
            'defaults' => [
                'cooldown_minutes' => (int) config('kannel.defaults.alert_cooldown_minutes', 15),
                'queue_threshold' => (int) config('kannel.defaults.queue_warning_threshold', 1000),
                'throughput_fraction' => (float) config('kannel.throughput.drop_threshold_fraction', 0.5),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $valid = $request->validate(array_merge(
            $this->baseValidationRules(),
            ['name' => ['required', 'string', 'max:255', 'unique:kannel_alert_rules,name']],
        ));

        $valid = $this->normalizeValidationData($valid);

        KannelAlertRule::query()->create($valid);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert rule created.')]);

        return to_route('kannel.alert-rules.index');
    }

    public function update(Request $request, KannelAlertRule $rule): RedirectResponse
    {
        $valid = $request->validate(array_merge(
            $this->baseValidationRules(),
            ['name' => ['required', 'string', 'max:255', Rule::unique('kannel_alert_rules', 'name')->ignore($rule->id)]],
        ));

        $valid = $this->normalizeValidationData($valid);

        $channels = $valid['channels'] ?? $rule->channels;
        if (is_array($channels)) {
            $channels = $this->maskSensitiveChannelClears($channels, $rule->channels ?? []);
            $valid['channels'] = $channels;
        }

        $rule->update($valid);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert rule updated.')]);

        return to_route('kannel.alert-rules.index');
    }

    public function destroy(KannelAlertRule $rule): RedirectResponse
    {
        $rule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert rule deleted.')]);

        return to_route('kannel.alert-rules.index');
    }

    /**
     * @return array<string, list<Rule|array<mixed>|string>>
     */
    private function baseValidationRules(): array
    {
        return [
            'endpoint_id' => ['nullable', 'integer', 'exists:kannel_endpoints,id'],
            'type' => ['required', 'string', Rule::enum(AlertRuleType::class)],
            'severity' => ['required', 'string', Rule::enum(AlertSeverity::class)],
            'threshold' => ['nullable', 'array'],
            'channels' => ['nullable', 'array'],
            'channels.*.type' => ['required', 'string', Rule::enum(AlertChannelType::class)],
            'channels.*.emails' => ['nullable', 'array'],
            'channels.*.emails.*' => ['nullable', 'email'],
            'channels.*.webhook_url' => ['nullable', 'string', 'url', 'max:511'],
            'channels.*.url' => ['nullable', 'string', 'url', 'max:511'],
            'channels.*.secret' => ['nullable', 'string', 'max:255'],
            'channels.*.method' => ['nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            'channels.*.headers' => ['nullable', 'array'],
            'channels.*.headers.*' => ['nullable', 'array'],
            'channels.*.headers.*.name' => ['required_with:channels.*.headers.*.value', 'string', 'max:255'],
            'channels.*.headers.*.value' => ['nullable', 'string', 'max:1000'],
            'cooldown_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'enabled' => ['boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeValidationData(array $data): array
    {
        if (isset($data['type'])) {
            $type = $data['type'] instanceof AlertRuleType ? $data['type'] : AlertRuleType::from((string) $data['type']);
            $threshold = is_array($data['threshold'] ?? null) ? $data['threshold'] : [];

            switch ($type) {
                case AlertRuleType::SmscDown:
                    $threshold = array_intersect_key($threshold, ['consecutive_failures' => true, 'include_states' => true]);

                    break;

                case AlertRuleType::QueueExceeded:
                    $threshold = [
                        'any_queue' => (int) ($threshold['any_queue'] ?? config('kannel.defaults.queue_warning_threshold', 1000)),
                        'inbound_queue' => isset($threshold['inbound_queue']) ? (int) $threshold['inbound_queue'] : null,
                        'outbound_queue' => isset($threshold['outbound_queue']) ? (int) $threshold['outbound_queue'] : null,
                        'dlr_queue' => isset($threshold['dlr_queue']) ? (int) $threshold['dlr_queue'] : null,
                    ];

                    break;

                case AlertRuleType::ThroughputDrop:
                    $threshold = [
                        'fraction' => (float) ($threshold['fraction'] ?? config('kannel.throughput.drop_threshold_fraction', 0.5)),
                        'baseline_hours' => (int) ($threshold['baseline_hours'] ?? config('kannel.throughput.baseline_hours', 24)),
                        'rate_window_minutes' => (int) ($threshold['rate_window_minutes'] ?? config('kannel.throughput.rate_window_minutes', 5)),
                    ];

                    break;

                case AlertRuleType::EndpointUnreachable:
                    $threshold = [
                        'consecutive_failures' => (int) ($threshold['consecutive_failures'] ?? config('kannel.consecutive_failures_before_unreachable', 3)),
                    ];

                    break;
            }

            $data['threshold'] = $threshold;
        }

        return $data;
    }

    /**
     * Replace masked secret placeholders ('***') with original values from $existing.
     *
     * @param  array<int, mixed>  $incoming
     * @param  array<int, mixed>  $existing
     * @return array<int, mixed>
     */
    private function maskSensitiveChannelClears(array $incoming, array $existing): array
    {
        foreach ($incoming as $idx => $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $type = $channel['type'] ?? null;
            $existingChannel = is_array($existing[$idx] ?? null) ? $existing[$idx] : [];

            if ($type === AlertChannelType::Webhook->value) {
                if (isset($channel['secret']) && (is_string($channel['secret']) && trim($channel['secret']) === '')) {
                    $incoming[$idx]['secret'] = $existingChannel['secret'] ?? null;
                }
                if (isset($channel['headers']) && is_array($channel['headers']) && isset($existingChannel['headers']) && is_array($existingChannel['headers'])) {
                    foreach ($channel['headers'] as $hIdx => $header) {
                        if (! is_array($header)) {
                            continue;
                        }
                        $value = $header['value'] ?? null;
                        $existingHeader = is_array($existingChannel['headers'][$hIdx] ?? null) ? $existingChannel['headers'][$hIdx] : [];
                        if (($value === '' || $value === null) && isset($existingHeader['name']) && ($header['name'] ?? null) === $existingHeader['name']) {
                            $incoming[$idx]['headers'][$hIdx]['value'] = $existingHeader['value'] ?? '';
                        }
                    }
                }
            }

            if ($type === AlertChannelType::Slack->value) {
                if (isset($channel['webhook_url']) && (is_string($channel['webhook_url']) && trim($channel['webhook_url']) === '')) {
                    $incoming[$idx]['webhook_url'] = $existingChannel['webhook_url'] ?? null;
                }
            }
        }

        return $incoming;
    }
}
