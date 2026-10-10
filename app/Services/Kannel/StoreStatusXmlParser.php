<?php

namespace App\Services\Kannel;

use App\DataObjects\Kannel\StoreMessageData;
use DateTimeImmutable;
use SimpleXMLElement;

class StoreStatusXmlParser
{
    /**
     * @return list<StoreMessageData>
     */
    public function parse(string $payload): array
    {
        $xml = simplexml_load_string($payload, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING);

        if ($xml === false) {
            return [];
        }

        $messages = [];

        foreach ($xml->message ?? [] as $msgNode) {
            $rawTime = trim((string) ($msgNode->time ?? ''));
            $time = null;
            if ($rawTime !== '') {
                $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawTime);
                if ($parsed !== false) {
                    $time = $parsed;
                } else {
                    $stamp = strtotime($rawTime);
                    if ($stamp !== false) {
                        $time = new DateTimeImmutable('@'.$stamp);
                    }
                }
            }

            $id = trim((string) ($msgNode->id ?? ''));
            $messages[] = new StoreMessageData(
                id: $id === '' ? bin2hex(random_bytes(6)) : $id,
                type: trim((string) ($msgNode->type ?? 'UNKNOWN')),
                time: $time,
                sender: $this->stringOrNull($msgNode, 'sender'),
                receiver: $this->stringOrNull($msgNode, 'receiver'),
                smscId: $this->stringOrNull($msgNode, 'smsc-id'),
                boxId: $this->stringOrNull($msgNode, 'box-id'),
                flags: $this->stringOrNull($msgNode, 'flags'),
                udhData: $this->stringOrNull($msgNode, 'udh-data'),
                msgData: $this->stringOrNull($msgNode, 'msg-data'),
                rawXml: $msgNode->asXML() ?: null,
            );
        }

        return $messages;
    }

    private function stringOrNull(SimpleXMLElement $node, string $name): ?string
    {
        if (! isset($node->{$name})) {
            return null;
        }
        $value = trim((string) $node->{$name});

        return $value === '' ? null : $value;
    }
}
