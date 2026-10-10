<?php

namespace App\Services\Kannel;

use App\Contracts\KannelMetricsParser;
use App\DataObjects\Kannel\KannelSnapshotData;
use App\DataObjects\Kannel\SmscMetricsData;
use App\Enums\SMSCState;
use DateTimeImmutable;
use SimpleXMLElement;

class XmlStatusParser implements KannelMetricsParser
{
    public function supports(string $contentType): bool
    {
        return str_contains(strtolower($contentType), 'xml')
            || str_ends_with(strtolower($contentType), '.xml');
    }

    /**
     * @return array{0: KannelSnapshotData, 1: list<SmscMetricsData>}
     */
    public function parse(string $payload): array
    {
        $xml = @simplexml_load_string($payload, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING);

        if ($xml === false) {
            throw new \RuntimeException('Unable to parse Kannel status XML payload.');
        }

        $smsBlock = $xml->sms ?? null;
        $wdpBlock = $xml->wdp ?? null;
        $dlrBlock = $xml->dlr ?? null;

        $hasBlocks = $smsBlock !== null || $wdpBlock !== null || $dlrBlock !== null;
        $hasTopLevelReceived = isset($xml->received);

        if (! $hasBlocks || $hasTopLevelReceived) {
            return $this->parseFlat($xml, $smsBlock, $dlrBlock);
        }

        return $this->parseNestedBlocks($xml, $smsBlock, $wdpBlock, $dlrBlock);
    }

    /**
     * @return array{0: KannelSnapshotData, 1: list<SmscMetricsData>}
     */
    private function parseFlat(SimpleXMLElement $xml, mixed $smsBlock, mixed $dlrBlock): array
    {
        $snapshot = new KannelSnapshotData(
            uptimeSeconds: (int) (string) ($xml->uptime ?? $this->parseUptime((string) ($xml->status ?? ''))),
            moTotal: (int) (string) ($xml->received ?? $xml->mo_total ?? 0),
            mtTotal: (int) (string) ($xml->sent ?? $xml->mt_total ?? 0),
            inboundQueue: (int) (string) ($xml->inbound_queue ?? $xml->inbound ?? 0),
            outboundQueue: (int) (string) ($xml->outbound_queue ?? $xml->outbound ?? 0),
            dlrQueue: (int) (string) ($xml->dlr_queue ?? $xml->dlr ?? 0),
            failedCount: (int) (string) ($xml->failed ?? 0),
            received: (int) (string) ($xml->received ?? 0),
            sent: (int) (string) ($xml->sent ?? 0),
            smsQueued: (int) (string) ($xml->queued ?? $xml->sms_queued ?? $xml->{'sms:queued'} ?? 0),
            storeSize: (int) (string) ($xml->store_size ?? $xml->store_size_kb ?? 0),
            storeUsed: (int) (string) ($xml->store_used ?? 0),
            bufferedMessages: (int) (string) ($xml->buffered ?? $xml->messages_buffered ?? 0),
            errors: $this->parseErrors($xml),
            boxId: $this->string($xml, 'box_id'),
            collectedAt: new DateTimeImmutable,
        );

        return [$snapshot, $this->parseFlatSmscs($xml)];
    }

    /**
     * @return list<SmscMetricsData>
     */
    private function parseFlatSmscs(SimpleXMLElement $xml): array
    {
        $out = [];
        if (! isset($xml->smscs->smsc)) {
            return $out;
        }

        foreach ($xml->smscs->smsc as $smscNode) {
            $lastSeen = $this->int($smscNode, 'last_seen');
            $out[] = new SmscMetricsData(
                smscName: $this->string($smscNode, 'name') ?: $this->string($smscNode, 'admin-id') ?: 'unnamed',
                state: $this->mapStateFlat($this->string($smscNode, 'status')),
                received: $this->int($smscNode, 'received'),
                sent: $this->int($smscNode, 'sent'),
                failed: $this->int($smscNode, 'failed'),
                queued: $this->int($smscNode, 'queued'),
                moCount: $this->int($smscNode, 'mo_count') ?: $this->int($smscNode, 'received'),
                mtCount: $this->int($smscNode, 'mt_count') ?: $this->int($smscNode, 'sent'),
                latencyMs: $this->int($smscNode, 'latency_ms'),
                lastSeenAt: $lastSeen > 0 ? (new DateTimeImmutable)->setTimestamp($lastSeen) : null,
                smscId: $this->string($smscNode, 'id') ?: $this->string($smscNode, 'admin-id'),
            );
        }

        return $out;
    }

    /**
     * @return array{0: KannelSnapshotData, 1: list<SmscMetricsData>}
     */
    private function parseNestedBlocks(SimpleXMLElement $xml, mixed $smsBlock, mixed $wdpBlock, mixed $dlrBlock): array
    {
        $smsReceivedTotal = $this->blockTotal($smsBlock, 'received');
        $smsReceivedQueued = $this->blockQueued($smsBlock, 'received');
        $smsSentTotal = $this->blockTotal($smsBlock, 'sent');
        $smsSentQueued = $this->blockQueued($smsBlock, 'sent');

        $wdpReceivedTotal = $this->blockTotal($wdpBlock, 'received');
        $wdpSentTotal = $this->blockTotal($wdpBlock, 'sent');

        $dlrReceivedTotal = $this->blockTotal($dlrBlock, 'received');
        $dlrSentTotal = $this->blockTotal($dlrBlock, 'sent');

        $inboundRaw = $this->int($smsBlock, 'inbound');
        $outboundRaw = $this->int($smsBlock, 'outbound');

        $moTotal = $smsReceivedTotal + $wdpReceivedTotal;
        $mtTotal = $smsSentTotal + $wdpSentTotal;
        $smsQueued = $smsReceivedQueued + $smsSentQueued;

        $snapshot = new KannelSnapshotData(
            uptimeSeconds: $this->parseUptime((string) ($xml->status ?? '')),
            moTotal: $moTotal,
            mtTotal: $mtTotal,
            inboundQueue: $this->int($xml, 'inbound_queue') ?: $this->int($xml, 'inbound') ?: $inboundRaw,
            outboundQueue: $this->int($xml, 'outbound_queue') ?: $this->int($xml, 'outbound') ?: $outboundRaw,
            dlrQueue: $this->int($xml, 'dlr_queue') ?: $this->int($dlrBlock, 'queued') ?: $this->int($xml, 'dlr'),
            failedCount: $this->int($xml, 'failed'),
            received: $moTotal + $dlrReceivedTotal,
            sent: $mtTotal + $dlrSentTotal,
            smsQueued: $this->int($xml, 'queued') ?: $this->int($xml, 'sms_queued') ?: $this->int($xml, 'sms:queued') ?: $smsQueued,
            storeSize: $this->int($xml, 'store_size') ?: $this->int($xml, 'store_size_kb') ?: $this->int($smsBlock, 'storesize'),
            storeUsed: $this->int($xml, 'store_used'),
            bufferedMessages: $this->int($xml, 'buffered') ?: $this->int($xml, 'messages_buffered') ?: 0,
            errors: $this->parseErrors($xml),
            boxId: $this->string($xml, 'box_id') ?: $this->string($xml->boxes->box ?? null, 'id'),
            collectedAt: new DateTimeImmutable,
        );

        $smscs = $this->parseSmscs($xml);

        return [$snapshot, $smscs];
    }

    /**
     * @return list<SmscMetricsData>
     */
    private function parseSmscs(SimpleXMLElement $xml): array
    {
        $smscs = [];

        if (! isset($xml->smscs->smsc)) {
            return $smscs;
        }

        foreach ($xml->smscs->smsc as $smscNode) {
            $state = $this->mapState($this->string($smscNode, 'status'));
            $lastSeen = $this->int($smscNode, 'last_seen');

            $smsSub = $smscNode->sms ?? null;
            $smsReceived = $this->int($smsSub, 'received');
            $smsSent = $this->int($smsSub, 'sent');

            $topReceived = $this->int($smscNode, 'received');
            $topSent = $this->int($smscNode, 'sent');

            $moCount = $this->int($smscNode, 'mo_count') ?: ($topReceived ?: $smsReceived);
            $mtCount = $this->int($smscNode, 'mt_count') ?: ($topSent ?: $smsSent);

            $smscs[] = new SmscMetricsData(
                smscName: $this->string($smscNode, 'name') ?: $this->string($smscNode, 'admin-id') ?: 'unnamed',
                state: $state,
                received: $topReceived ?: $smsReceived,
                sent: $topSent ?: $smsSent,
                failed: $this->int($smscNode, 'failed'),
                queued: $this->int($smscNode, 'queued'),
                moCount: $moCount,
                mtCount: $mtCount,
                latencyMs: $this->int($smscNode, 'latency_ms'),
                lastSeenAt: $lastSeen > 0 ? (new DateTimeImmutable)->setTimestamp($lastSeen) : null,
                smscId: $this->string($smscNode, 'id') ?: $this->string($smscNode, 'admin-id'),
            );
        }

        return $smscs;
    }

    private function blockTotal(mixed $block, string $which): int
    {
        if (! $block instanceof SimpleXMLElement) {
            return 0;
        }
        if (! isset($block->{$which})) {
            return 0;
        }
        $node = $block->{$which};
        if (isset($node->total)) {
            return (int) (string) $node->total;
        }

        return (int) (string) $node;
    }

    private function blockQueued(mixed $block, string $which): int
    {
        if (! $block instanceof SimpleXMLElement) {
            return 0;
        }
        if (! isset($block->{$which})) {
            return 0;
        }
        $node = $block->{$which};
        if (isset($node->queued)) {
            return (int) (string) $node->queued;
        }
        if (isset($node['queued'])) {
            return (int) (string) $node['queued'];
        }

        return 0;
    }

    /**
     * @return array<int, array{code:string, count:int, smsc?:string, description?:string}>
     */
    private function parseErrors(SimpleXMLElement $xml): array
    {
        $errors = [];

        if (! isset($xml->errors->error)) {
            return $errors;
        }

        foreach ($xml->errors->error as $error) {
            $entry = [
                'code' => (string) $this->attributeOrChild($error, 'code'),
                'count' => (int) $this->attributeOrChild($error, 'count'),
            ];

            $smsc = (string) $this->attributeOrChild($error, 'smsc');
            if ($smsc !== '') {
                $entry['smsc'] = $smsc;
            }

            $description = (string) $this->attributeOrChild($error, 'description');
            if ($description !== '') {
                $entry['description'] = $description;
            }

            $errors[] = $entry;
        }

        return $errors;
    }

    private function parseUptime(string $rawStatus): int
    {
        if ($rawStatus === '') {
            return 0;
        }
        $days = 0;
        $hours = 0;
        $minutes = 0;
        $seconds = 0;
        if (preg_match('/uptime\s+(\d+)d\s+(\d+)h\s+(\d+)m\s+(\d+)s/', $rawStatus, $m)) {
            [, $days, $hours, $minutes, $seconds] = array_map('intval', $m);
        } elseif (preg_match('/uptime\s+(\d+)m\s+(\d+)s/', $rawStatus, $m)) {
            [, $minutes, $seconds] = array_map('intval', $m);
        } elseif (preg_match('/uptime\s+(\d+)s/', $rawStatus, $m)) {
            $seconds = (int) $m[1];
        }

        return $days * 86400 + $hours * 3600 + $minutes * 60 + $seconds;
    }

    private function int(mixed $node, string $name): int
    {
        if ($node instanceof SimpleXMLElement && isset($node->{$name})) {
            return (int) (string) $node->{$name};
        }
        if (is_array($node) && isset($node[$name])) {
            return (int) $node[$name];
        }

        return 0;
    }

    private function string(mixed $node, string $name): ?string
    {
        if ($node instanceof SimpleXMLElement && isset($node->{$name})) {
            $value = trim((string) $node->{$name});

            return $value === '' ? null : $value;
        }
        if (is_array($node) && isset($node[$name]) && is_scalar($node[$name])) {
            $value = trim((string) $node[$name]);

            return $value === '' ? null : $value;
        }

        return null;
    }

    private function attributeOrChild(SimpleXMLElement $node, string $name): string
    {
        $attr = $node[$name] ?? null;
        if ($attr !== null) {
            return trim((string) $attr);
        }

        if (isset($node->{$name})) {
            return trim((string) $node->{$name});
        }

        return '';
    }

    private function mapStateFlat(?string $raw): SMSCState
    {
        return $this->mapState($raw, true);
    }

    private function mapState(?string $raw, bool $strict = false): SMSCState
    {
        $raw = strtolower((string) $raw);

        return match (true) {
            $raw === 'connected' || str_starts_with($raw, 'online') || (! $strict && str_contains($raw, 'on-line') && ! str_contains($raw, 'connecting')) => SMSCState::Connected,
            str_contains($raw, 'reconnect') || str_contains($raw, 're-connect') => SMSCState::Reconnecting,
            (! $strict && str_contains($raw, 'connecting')) || str_contains($raw, 'bind') => SMSCState::Binding,
            str_contains($raw, 'disconnect') || $raw === 'dead' => SMSCState::Disconnected,
            str_contains($raw, 'idle') => SMSCState::Idle,
            default => SMSCState::Unknown,
        };
    }
}
