<?php

declare(strict_types=1);

namespace E98\YouTrack;

/**
 * Builders for the custom field payloads YouTrack expects when creating or
 * updating issues. Every field needs its `$type`, so these helpers keep that
 * knowledge in one place.
 *
 *     CustomField::enum('Priority', 'Critical')
 *     CustomField::user('Assignee', 'anna')
 *
 * @phpstan-type FieldPayload array{name: string, '$type': string, value: mixed}
 */
final class CustomField
{
    /** Single-value enum field, e.g. Priority, Type. */
    public static function enum(string $name, string $value): array
    {
        return self::make($name, 'SingleEnumIssueCustomField', ['name' => $value]);
    }

    /**
     * Multi-value enum field.
     *
     * @param  list<string>  $values
     */
    public static function multiEnum(string $name, array $values): array
    {
        return self::make(
            $name,
            'MultiEnumIssueCustomField',
            array_map(static fn (string $v): array => ['name' => $v], array_values($values)),
        );
    }

    /** State field (the issue status). */
    public static function state(string $name, string $value): array
    {
        return self::make($name, 'StateIssueCustomField', ['name' => $value]);
    }

    /** Single-user field, e.g. Assignee. Pass null to unassign. */
    public static function user(string $name, ?string $login): array
    {
        return self::make($name, 'SingleUserIssueCustomField', $login === null ? null : ['login' => $login]);
    }

    /** Single "owned" field, e.g. Subsystem. */
    public static function owned(string $name, string $value): array
    {
        return self::make($name, 'SingleOwnedIssueCustomField', ['name' => $value]);
    }

    /** Single version field, e.g. Fix versions / Affected versions. */
    public static function version(string $name, string $value): array
    {
        return self::make($name, 'SingleVersionIssueCustomField', ['name' => $value]);
    }

    /** Multi-line text field. */
    public static function text(string $name, string $value): array
    {
        return self::make($name, 'TextIssueCustomField', ['text' => $value]);
    }

    /** Simple scalar field (string, integer or float). */
    public static function simple(string $name, string|int|float|null $value): array
    {
        return self::make($name, 'SimpleIssueCustomField', $value);
    }

    private static function make(string $name, string $type, mixed $value): array
    {
        return ['name' => $name, '$type' => $type, 'value' => $value];
    }
}
