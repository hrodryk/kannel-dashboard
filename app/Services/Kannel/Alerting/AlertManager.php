<?php

namespace App\Services\Kannel\Alerting;

use App\DataObjects\Kannel\AlertEvaluationResult;
use App\Enums\AlertChannelType;
use App\Models\KannelAlertEvent;
use App\Models\KannelAlertRule;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\KannelAlertNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class AlertManager
{
    /**
     * @var array<string, int|string>
     */
    private array $lastEmittedKeyForFingerprint = [];

    public function __construct(
        protected RuleEvaluator $evaluator,
    ) {}

    /**
     * @param  iterable<KannelAlertRule>  $rules
     * @param  array<string, mixed>  $context
     * @return list<KannelAlertEvent>
     */
    public function runEvaluation(iterable $rules, array $context): array
    {
        $events = [];

        foreach ($rules as $rule) {
            if (! $rule->enabled) {
                continue;
            }

            try {
                $results = $this->evaluator->evaluate($rule, $context);
            } catch (Throwable $e) {
                Log::channel('kannel')->warning('kannel.alert.evaluate_error', [
                    'rule_id' => $rule->id,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($results as $result) {
                if (! $result->triggered) {
                    continue;
                }

                $fingerprint = $result->fingerprint($rule->id);
                $lastEmittedKey = $this->lastEmittedKeyForFingerprint[$fingerprint] ?? null;
                $eventBefore = KannelAlertEvent::query()
                    ->where('fingerprint', $fingerprint)
                    ->value('last_notified_at');
                $event = $this->recordAndNotify($rule, $result);
                $currentKey = $event->getKey();

                $wasEmittedBefore = $lastEmittedKey !== null
                    && (string) $lastEmittedKey === (string) $currentKey;
                $notificationHappened = $eventBefore === null
                    || $event->last_notified_at === null
                    || Carbon::parse($event->last_notified_at)->gt(Carbon::parse($eventBefore));

                if (! $wasEmittedBefore || $notificationHappened) {
                    $events[] = $event;
                }
                $this->lastEmittedKeyForFingerprint[$fingerprint] = $currentKey;
            }
        }

        return $events;
    }

    private function recordAndNotify(KannelAlertRule $rule, AlertEvaluationResult $result): KannelAlertEvent
    {
        $fingerprint = $result->fingerprint($rule->id);

        $event = KannelAlertEvent::query()->firstOrNew([
            'fingerprint' => $fingerprint,
        ]);

        $isNew = ! $event->exists;
        $cooldownMinutes = max(0, (int) $rule->cooldown_minutes);
        $cooldownElapsed = true;
        if (! $isNew && $event->last_notified_at !== null && $cooldownMinutes > 0) {
            $cooldownElapsed = Carbon::instance($event->last_notified_at)
                ->addMinutes($cooldownMinutes)
                ->isPast();
        }

        if ($isNew) {
            $event->rule_id = $rule->id;
            $event->endpoint_id = $result->endpointId;
            $event->smsc_name = $result->smscName;
            $event->fingerprint = $fingerprint;
            $event->state = 'firing';
            $event->severity = $result->severity;
            $event->title = $rule->name;
            $event->message = $result->message;
            $event->payload = $result->context;
            $event->fired_at = now();
        } else {
            $event->message = $result->message;
            $event->payload = $result->context;
            if ($event->state !== 'firing') {
                $event->state = 'firing';
                $event->resolved_at = null;
                $event->fired_at = now();
            }
        }

        $shouldNotify = $isNew || $cooldownElapsed;

        if ($shouldNotify) {
            try {
                $this->dispatchNotifications($rule, $event, $result);
                $event->last_notified_at = now();
                $event->notify_attempts = (int) $event->notify_attempts + 1;
            } catch (Throwable $e) {
                Log::channel('kannel')->error('kannel.alert.notification_failed', [
                    'rule_id' => $rule->id,
                    'fingerprint' => $fingerprint,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $event->save();

        return $event;
    }

    private function dispatchNotifications(KannelAlertRule $rule, KannelAlertEvent $event, AlertEvaluationResult $result): void
    {
        /** @var list<mixed> $channels */
        $channels = $rule->channelsPlain();

        foreach ($channels as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            /** @var mixed $typeRaw */
            $typeRaw = $channel['type'] ?? 'mail';
            $type = is_string($typeRaw) ? $typeRaw : 'mail';
            $notification = new KannelAlertNotification($rule, $event, $result);

            switch ($type) {
                case AlertChannelType::Mail->value:
                    $emails = isset($channel['emails']) && is_array($channel['emails'])
                        ? $channel['emails']
                        : (isset($channel['to']) ? [(string) $channel['to']] : []);
                    if ($emails !== []) {
                        collect($emails)
                            ->filter(static function (mixed $email): bool {
                                if (! is_string($email)) {
                                    return false;
                                }
                                $filtered = filter_var($email, FILTER_VALIDATE_EMAIL);

                                return $filtered !== false && $filtered !== null;
                            })
                            ->each(function (string $email) use ($notification): void {
                                Notification::route('mail', $email)->notify($notification);
                            });
                    }

                    break;

                case AlertChannelType::Slack->value:
                    $webhookUrl = isset($channel['webhook_url']) && is_string($channel['webhook_url'])
                        ? $channel['webhook_url']
                        : null;
                    if ($webhookUrl !== null && $webhookUrl !== '') {
                        Notification::route(WebhookChannel::class, [
                            'url' => $webhookUrl,
                            'method' => 'POST',
                        ])->notify($notification);
                    }

                    break;

                case AlertChannelType::Webhook->value:
                    $webUrl = isset($channel['url']) && is_string($channel['url'])
                        ? $channel['url']
                        : null;
                    if ($webUrl === null || $webUrl === '') {
                        break;
                    }

                    $headersRaw = $channel['headers'] ?? [];
                    $headers = is_array($headersRaw) ? $headersRaw : [];
                    $methodRaw = $channel['method'] ?? 'POST';
                    $method = is_string($methodRaw) ? $methodRaw : 'POST';
                    $secretRaw = $channel['secret'] ?? null;
                    $secret = is_string($secretRaw) ? $secretRaw : null;

                    Notification::route(WebhookChannel::class, [
                        'url' => $webUrl,
                        'secret' => $secret,
                        'headers' => $headers,
                        'method' => $method,
                    ])->notify($notification);

                    break;
            }
        }
    }
}
