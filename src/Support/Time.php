<?php

declare(strict_types=1);

namespace E98\YouTrack\Support;

use Carbon\CarbonImmutable;

/**
 * YouTrack sends timestamps as milliseconds since the Unix epoch.
 */
final class Time
{
    public static function fromMillis(mixed $millis): ?CarbonImmutable
    {
        return is_numeric($millis) ? CarbonImmutable::createFromTimestampMs((int) $millis) : null;
    }
}
