<?php

declare(strict_types=1);

namespace E98\YouTrack\Data;

/**
 * An issue link type, e.g. "Subtask": outward "parent for", inward "subtask of".
 * Undirected types ("Relates") have the same phrase in both directions.
 */
final readonly class LinkType
{
    public function __construct(
        public string $id,
        public string $name,
        public string $outward,
        public string $inward,
        public bool $directed,
        public bool $aggregation,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $outward = (string) ($data['sourceToTarget'] ?? '');
        $inward = (string) ($data['targetToSource'] ?? '');

        return new self(
            (string) $data['id'],
            (string) ($data['name'] ?? ''),
            $outward,
            $inward !== '' ? $inward : $outward,
            (bool) ($data['directed'] ?? false),
            (bool) ($data['aggregation'] ?? false),
        );
    }

    /**
     * Find the link type and direction a phrase such as "subtask of" stands for.
     * The phrase describes how the issue you link FROM relates to the other one:
     * link('DEV-2', 'subtask of', 'DEV-1') makes DEV-2 a subtask of DEV-1.
     *
     * @param  iterable<self>  $types
     * @return array{0: self, 1: bool}|null The type and whether the phrase is its outward one.
     */
    public static function match(iterable $types, string $phrase): ?array
    {
        $needle = mb_strtolower(trim($phrase));

        foreach ($types as $type) {
            if ($needle === mb_strtolower($type->outward)) {
                return [$type, true];
            }

            if ($type->directed && $needle === mb_strtolower($type->inward)) {
                return [$type, false];
            }

            if (! $type->directed && $needle === mb_strtolower($type->name)) {
                return [$type, true];
            }
        }

        return null;
    }

    /**
     * The id the REST API addresses one side of this type with: "80-1s" (outward),
     * "80-1t" (inward), or the bare id for undirected types.
     */
    public function linkId(bool $outward): string
    {
        if (! $this->directed) {
            return $this->id;
        }

        return $this->id . ($outward ? 's' : 't');
    }

    /**
     * Every phrase that can be used with this type.
     *
     * @return list<string>
     */
    public function phrases(): array
    {
        return array_values(array_unique(array_filter([$this->outward, $this->directed ? $this->inward : null])));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'outward' => $this->outward,
            'inward' => $this->inward,
            'directed' => $this->directed,
            'aggregation' => $this->aggregation,
        ];
    }
}
