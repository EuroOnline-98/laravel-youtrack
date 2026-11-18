<?php

declare(strict_types=1);

namespace E98\YouTrack\Data;

use Carbon\CarbonImmutable;
use E98\YouTrack\Support\Time;

final readonly class Comment
{
    public function __construct(
        public string $id,
        public string $text,
        public ?User $author,
        public ?CarbonImmutable $created,
        public ?CarbonImmutable $updated,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) ($data['text'] ?? ''),
            User::fromArray($data['author'] ?? null),
            Time::fromMillis($data['created'] ?? null),
            Time::fromMillis($data['updated'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'author' => $this->author?->toArray(),
            'created' => $this->created?->toIso8601String(),
            'updated' => $this->updated?->toIso8601String(),
        ];
    }
}
