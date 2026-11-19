<?php

declare(strict_types=1);

namespace E98\YouTrack\Data;

/**
 * One link from an issue to another. Read it as "<this issue> <label> <key>":
 * `subtask of DEV-1`, `relates to DEV-5`, `parent for DEV-9`.
 */
final readonly class Link
{
    public function __construct(
        public string $type,
        public string $label,
        public string $direction,
        public string $id,
        public string $key,
        public string $summary,
        public bool $resolved,
    ) {
    }

    /**
     * @param  array<string, mixed>  $entry  One element of an issue's links: link type, direction, issues.
     * @param  array<string, mixed>  $issue  One of the entry's linked issues.
     */
    public static function fromEntry(array $entry, array $issue): self
    {
        $direction = (string) ($entry['direction'] ?? 'BOTH');
        $type = (array) ($entry['linkType'] ?? []);

        $outward = (string) ($type['sourceToTarget'] ?? '');
        $inward = (string) ($type['targetToSource'] ?? '');
        $label = $direction === 'INWARD' && $inward !== '' ? $inward : $outward;

        return new self(
            (string) ($type['name'] ?? ''),
            $label,
            $direction,
            (string) $issue['id'],
            (string) ($issue['idReadable'] ?? $issue['id']),
            (string) ($issue['summary'] ?? ''),
            ($issue['resolved'] ?? null) !== null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'direction' => $this->direction,
            'id' => $this->id,
            'key' => $this->key,
            'summary' => $this->summary,
            'resolved' => $this->resolved,
        ];
    }
}
