<?php

declare(strict_types=1);

namespace EchoRelay\Sdk;

use Psr\Http\Message\ResponseInterface;

/**
 * Thrown for any non-2xx response and for a request that never reached the
 * server (a PSR-18 `ClientExceptionInterface` — DNS/TCP/TLS failure, abort).
 * `status` is 0 for the latter case.
 *
 * `errorCode` and `requestId` come from the relay's flat error envelope,
 * `{"error":"<code>","requestId":"<uuid>"}` — present on every rejection the
 * relay itself produces, absent when the failure never reached it. Named
 * `errorCode` rather than `code` because `\Exception::$code` already claims
 * that name with an incompatible (untyped, non-readonly) declaration.
 */
final class EchoRelayError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Builds an `EchoRelayError` from a response already known to be
     * non-2xx. Reads the body once as text, then tries the relay's flat JSON
     * envelope; a body that isn't JSON (an intermediary's error page, for
     * example) still produces an error, keyed on status alone.
     */
    public static function fromResponse(ResponseInterface $response): self
    {
        $text = (string) $response->getBody();
        $errorCode = null;
        $requestId = null;
        if ($text !== '') {
            $parsed = json_decode($text, true);
            if (is_array($parsed)) {
                if (isset($parsed['error']) && is_string($parsed['error'])) {
                    $errorCode = $parsed['error'];
                }
                if (isset($parsed['requestId']) && is_string($parsed['requestId'])) {
                    $requestId = $parsed['requestId'];
                }
            }
        }

        $reasonPhrase = $response->getReasonPhrase();
        // Presence, not truthiness: PHP reads the string "0" as false, and an
        // error code or a body of "0" is a message the relay actually sent.
        $message = $errorCode
            ?? ($text !== '' ? $text : ($reasonPhrase !== '' ? $reasonPhrase : "HTTP {$response->getStatusCode()}"));

        return new self($message, $response->getStatusCode(), $errorCode, $requestId);
    }
}
