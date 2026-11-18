<?php

declare(strict_types=1);

namespace E98\YouTrack\Data;

final readonly class Project
{
    public function __construct(
        public string $id,
        public string $shortName,
        public ?string $name,
        public bool $archived,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) ($data['shortName'] ?? ''),
            $data['name'] ?? null,
            (bool) ($data['archived'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shortName' => $this->shortName,
            'name' => $this->name,
            'archived' => $this->archived,
        ];
    }
}
