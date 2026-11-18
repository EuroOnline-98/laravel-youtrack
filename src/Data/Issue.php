<?php

declare(strict_types=1);

namespace E98\YouTrack\Data;

use Carbon\CarbonImmutable;
use E98\YouTrack\Support\FieldValue;
use E98\YouTrack\Support\Time;

final readonly class Issue
{
    /**
     * @param  array<string, mixed>  $customFields  Normalized values keyed by field name.
     * @param  array<string, mixed>  $raw  The untouched API payload.
     */
    public function __construct(
        public string $id,
        public string $key,
        public string $summary,
        public ?string $description,
        public ?string $projectId,
        public ?string $project,
        public ?string $state,
        public bool $resolved,
        public ?CarbonImmutable $created,
        public ?CarbonImmutable $updated,
        public ?CarbonImmutable $resolvedAt,
        public ?User $reporter,
        public array $customFields,
        public ?string $url,
        public array $raw,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $baseUrl = null, string $stateField = 'State'): self
    {
        $customFields = [];
        foreach ($data['customFields'] ?? [] as $field) {
            if (isset($field['name'])) {
                $customFields[$field['name']] = FieldValue::normalize($field['value'] ?? null);
            }
        }

        $state = $customFields[$stateField] ?? null;
        $key = (string) ($data['idReadable'] ?? $data['id']);

        return new self(
            id: (string) $data['id'],
            key: $key,
            summary: (string) ($data['summary'] ?? ''),
            description: $data['description'] ?? null,
            projectId: $data['project']['id'] ?? null,
            project: $data['project']['shortName'] ?? null,
            state: is_string($state) ? $state : null,
            resolved: ($data['resolved'] ?? null) !== null,
            created: Time::fromMillis($data['created'] ?? null),
            updated: Time::fromMillis($data['updated'] ?? null),
            resolvedAt: Time::fromMillis($data['resolved'] ?? null),
            reporter: User::fromArray($data['reporter'] ?? null),
            customFields: $customFields,
            url: $baseUrl !== null ? rtrim($baseUrl, '/') . '/issue/' . $key : null,
            raw: $data,
        );
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * Normalized value of a custom field (name for enums/states, login for users, ...).
     */
    public function field(string $name, mixed $default = null): mixed
    {
        return $this->customFields[$name] ?? $default;
    }

    /**
     * Login of the assignee, if the project has an "Assignee" field.
     */
    public function assignee(): ?string
    {
        $value = $this->field('Assignee');

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'summary' => $this->summary,
            'description' => $this->description,
            'project' => $this->project,
            'state' => $this->state,
            'resolved' => $this->resolved,
            'created' => $this->created?->toIso8601String(),
            'updated' => $this->updated?->toIso8601String(),
            'resolvedAt' => $this->resolvedAt?->toIso8601String(),
            'reporter' => $this->reporter?->toArray(),
            'customFields' => $this->customFields,
            'url' => $this->url,
        ];
    }
}
