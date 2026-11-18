<?php

declare(strict_types=1);

namespace E98\YouTrack\Support;

/**
 * Collapses YouTrack's typed custom field values into plain PHP values:
 * enum/state/version -> its name, user -> login, period -> presentation,
 * text -> the text, multi-value -> a list of those.
 */
final class FieldValue
{
    public static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        foreach (['login', 'presentation', 'name', 'text', 'minutes'] as $key) {
            if (isset($value[$key])) {
                return $value[$key];
            }
        }

        return $value;
    }
}
