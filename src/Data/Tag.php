<?php

declare(strict_types=1);

namespace E98\YouTrack\Data;

final readonly class Tag
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $untagOnResolve,
        public ?User $owner,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) ($data['name'] ?? ''),
            (bool) ($data['untagOnResolve'] ?? false),
            User::fromArray($data['owner'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'untagOnResolve' => $this->untagOnResolve,
            'owner' => $this->owner?->toArray(),
        ];
    }
}
