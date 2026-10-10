<?php

namespace App\DataObjects\Kannel;

use DateTimeImmutable;

final readonly class StoreMessageData
{
    public function __construct(
        public string $id,
        public string $type,
        public ?DateTimeImmutable $time = null,
        public ?string $sender = null,
        public ?string $receiver = null,
        public ?string $smscId = null,
        public ?string $boxId = null,
        public ?string $flags = null,
        public ?string $udhData = null,
        public ?string $msgData = null,
        public ?string $rawXml = null,
    ) {}

    public function msgDataDecoded(): ?string
    {
        if ($this->msgData === null || $this->msgData === '') {
            return null;
        }
        $hasPlus = str_contains($this->msgData, '+');
        $decoded = rawurldecode($this->msgData);
        if ($hasPlus && $decoded === $this->msgData) {
            $decoded = strtr($this->msgData, ['+' => ' ']);
        }
        if ($decoded === '') {
            return $this->msgData;
        }

        return $decoded;
    }
}
