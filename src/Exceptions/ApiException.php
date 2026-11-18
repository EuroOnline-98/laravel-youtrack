<?php

declare(strict_types=1);

namespace E98\YouTrack\Exceptions;

use Throwable;

/**
 * YouTrack answered with a non-2xx status.
 */
class ApiException extends YouTrackException
{
    /**
     * @param  array<mixed>|null  $body  Decoded JSON error body, if any.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?array $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
