<?php

namespace App\Services\Kannel;

use App\Contracts\KannelMetricsParser;
use App\DataObjects\Kannel\KannelSnapshotData;
use App\DataObjects\Kannel\SmscMetricsData;
use App\Enums\SMSCState;
use DateTimeImmutable;

class JsonStatusParser implements KannelMetricsParser
{
    public function supports(string $contentType): bool
    {
        return str_contains(strtolower($contentType), 'json')
            || str_ends_with(strtolower($contentType), '.json');
    }

    /**
     * @return array{0: KannelSnapshotData, 1: list<SmscMetricsData>}
     */
    public function parse(string $payload): array
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        $gw = $data['gateway'] ?? $data;

        $snapshot = new KannelSnapshotData(
            uptimeSeconds: (int) ($gw['uptime'] ?? 0),
            moTotal: (int) ($gw['received'] ?? $gw['mo_total'] ?? 0),
            mtTotal: (int) ($gw['sent'] ?? $gw['mt_total'] ?? 0),
            inboundQueue: (int) ($gw['inbound_queue'] ?? $gw['inbound'] ?? 0),
            outboundQueue: (int) ($gw['outbound_queue'] ?? $gw['outbound'] ?? 0),
            dlrQueue: (int) ($gw['dlr_queue'] ?? $gw['dlr'] ?? 0),
            failedCount: (int) ($gw['failed'] ?? 0),
            received: (int) ($gw['received'] ?? 0),
            sent: (int) ($gw['sent'] ?? 0),
            smsQueued: (int) ($gw['queued'] ?? $gw['sms_queued'] ?? $gw['sms:queued'] ?? 0),
            storeSize: (int) ($gw['store_size'] ?? $gw['store_size_kb'] ?? 0),
            storeUsed: (int) ($gw['store_used'] ?? 0),
            bufferedMessages: (int) ($gw['buffered'] ?? $gw['messages_buffered'] ?? 0),
            errors: $this->parseErrors($gw),
            boxId: isset($gw['box_id']) ? (string) $gw['box_id'] : null,
            collectedAt: new DateTimeImmutable,
        );

        $smscs = [];
        foreach (($gw['smscs'] ?? []) as $smsc) {
            $lastSeen = isset($smsc['last_seen']) ? (int) $smsc['last_seen'] : 0;
            $smscs[] = new SmscMetricsData(
                smscName: (string) ($smsc['name'] ?? 'unnamed'),
                state: $this->mapState((string) ($smsc['status'] ?? '')),
                received: (int) ($smsc['received'] ?? 0),
                sent: (int) ($smsc['sent'] ?? 0),
                failed: (int) ($smsc['failed'] ?? 0),
                queued: (int) ($smsc['queued'] ?? 0),
                moCount: (int) ($smsc['mo_count'] ?? $smsc['received'] ?? 0),
                mtCount: (int) ($smsc['mt_count'] ?? $smsc['sent'] ?? 0),
                latencyMs: (int) ($smsc['latency_ms'] ?? 0),
                lastSeenAt: $lastSeen > 0 ? (new DateTimeImmutable)->setTimestamp($lastSeen) : null,
                smscId: isset($smsc['id']) ? (string) $smsc['id'] : null,
            );
        }

        return [$snapshot, $smscs];
    }

    /**
     * @param  array<string, mixed>  $gw
     * @return array<int, array{code:string, count:int, smsc?:string, description?:string}>
     */
    private function parseErrors(array $gw): array
    {
        $errors = [];

        foreach (($gw['errors'] ?? []) as $error) {
            if (! is_array($error)) {
                continue;
            }

            $entry = [
                'code' => (string) ($error['code'] ?? 'UNKNOWN'),
                'count' => (int) ($error['count'] ?? 1),
            ];

            if (isset($error['smsc']) && is_string($error['smsc'])) {
                $entry['smsc'] = $error['smsc'];
            }

            if (isset($error['description']) && is_string($error['description'])) {
                $entry['description'] = $error['description'];
            }

            $errors[] = $entry;
        }

        return $errors;
    }

    private function mapState(string $raw): SMSCState
    {
        $raw = strtolower($raw);

        return match (true) {
            $raw === 'connected' || str_starts_with($raw, 'online') || str_contains($raw, 'on-line') => SMSCState::Connected,
            str_contains($raw, 'reconnect') || str_contains($raw, 're-connect') => SMSCState::Reconnecting,
            str_contains($raw, 'connecting') || str_contains($raw, 'bind') => SMSCState::Binding,
            str_contains($raw, 'disconnect') || $raw === 'dead' => SMSCState::Disconnected,
            str_contains($raw, 'idle') => SMSCState::Idle,
            default => SMSCState::Unknown,
        };
    }
}
