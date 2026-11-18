<?php

declare(strict_types=1);

namespace E98\YouTrack\Exceptions;

/**
 * HTTP 404: the issue, project or other resource does not exist (or is not visible).
 */
class NotFoundException extends ApiException
{
}
