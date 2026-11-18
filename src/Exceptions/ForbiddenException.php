<?php

declare(strict_types=1);

namespace E98\YouTrack\Exceptions;

/**
 * HTTP 403: the token's user lacks the permission for this operation.
 */
class ForbiddenException extends ApiException
{
}
