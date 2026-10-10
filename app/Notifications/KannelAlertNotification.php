<?php

namespace App\Notifications;

use App\DataObjects\Kannel\AlertEvaluationResult;
use App\Enums\AlertRuleType;
use App\Models\KannelAlertEvent;
use App\Models\KannelAlertRule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackAttachment;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class KannelAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public KannelAlertRule $rule,
        public KannelAlertEvent $event,
        public AlertEvaluationResult $result,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            $routes = array_keys($notifiable->routes);
            $normalized = [];
            foreach ($routes as $route) {
                if (str_contains($route, '\\')) {
                    $normalized[] = $route;
                } else {
                    $normalized[] = $route;
                }
            }

            return $normalized;
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $level = $this->result->severity->value === 'critical' ? 'error' : $this->result->severity->value;

        return (new MailMessage)
            ->subject(sprintf('[KANNEL][%s] %s', strtoupper($this->result->severity->value), $this->event->title))
            ->greeting('Kannel Alert')
            ->level($level === 'info' ? 'info' : $level)
            ->line($this->event->message)
            ->line('Rule: '.$this->rule->name)
            ->line('Endpoint ID: '.($this->event->endpoint_id ?? '(none)'))
            ->when($this->event->smsc_name !== null, fn (MailMessage $m) => $m->line('SMSC: '.$this->event->smsc_name))
            ->line('Triggered at: '.$this->event->fired_at->toDateTimeString());
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $color = match ($this->result->severity->value) {
            'critical' => 'danger',
            'warning' => 'warning',
            default => 'good',
        };

        return (new SlackMessage)
            ->from(config('app.name'), ':rocket:')
            ->error($color === 'danger')
            ->warning($color === 'warning')
            ->success($color === 'good')
            ->content(sprintf('*Kannel %s Alert:* %s', Str::upper($this->result->severity->value), $this->event->title))
            ->attachment(function (SlackAttachment $attachment): void {
                $attachment->content($this->event->message);
                $attachment->fields(array_filter([
                    'Rule' => $this->rule->name,
                    'Endpoint ID' => (string) ($this->event->endpoint_id ?? '(none)'),
                    'SMSC' => $this->event->smsc_name,
                    'Fired at' => $this->event->fired_at?->toDateTimeString() ?? now()->toDateTimeString(),
                ], fn ($v): bool => $v !== null));
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebhook(object $notifiable): array
    {
        return [
            'event' => 'kannel.alert.fired',
            'id' => $this->event->id,
            'fingerprint' => $this->event->fingerprint,
            'severity' => $this->result->severity->value,
            'rule' => [
                'id' => $this->rule->id,
                'name' => $this->rule->name,
                'type' => $this->rule->type instanceof AlertRuleType ? $this->rule->type->value : (string) $this->rule->type,
            ],
            'endpoint_id' => $this->event->endpoint_id,
            'smsc_name' => $this->event->smsc_name,
            'state' => $this->event->state,
            'title' => $this->event->title,
            'message' => $this->event->message,
            'context' => $this->result->context,
            'fired_at' => $this->event->fired_at?->toIso8601String() ?? now()->toIso8601String(),
            'notify_attempts' => $this->event->notify_attempts,
            'source_app' => config('app.name'),
            'source_url' => config('app.url'),
        ];
    }
}
