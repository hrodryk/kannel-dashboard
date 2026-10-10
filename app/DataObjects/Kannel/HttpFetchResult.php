<?php

namespace App\DataObjects\Kannel;

final readonly class HttpFetchResult
{
    public function __construct(
        public bool $success,
        public int $status,
        public string $body,
        public int $durationMs,
        public int $retries,
        public ?string $errorMessage = null,
    ) {}
}
