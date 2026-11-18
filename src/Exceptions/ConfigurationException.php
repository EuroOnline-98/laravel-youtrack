<?php

declare(strict_types=1);

namespace E98\YouTrack\Exceptions;

/**
 * The package is not configured (missing base URL, token or project).
 */
class ConfigurationException extends YouTrackException
{
}
