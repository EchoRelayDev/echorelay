<?php

declare(strict_types=1);

namespace EchoRelay\Sdk;

/** The target's forwarded reply, as returned by a `sync`-configured endpoint. */
final readonly class SyncResult
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public mixed $body,
    ) {
    }
}
