<?php

declare(strict_types=1);

namespace EchoRelay\Sdk\Tests;

use Psr\Http\Client\ClientExceptionInterface;

/** A transport failure that never reached the relay — DNS, TCP, TLS, abort. */
final class StubClientException extends \RuntimeException implements ClientExceptionInterface
{
}
