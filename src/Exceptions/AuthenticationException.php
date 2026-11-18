<?php

declare(strict_types=1);

namespace E98\YouTrack\Exceptions;

/**
 * HTTP 401: the token is missing, invalid or expired.
 */
class AuthenticationException extends ApiException
{
}
