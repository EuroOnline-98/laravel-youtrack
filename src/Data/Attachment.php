<?php

declare(strict_types=1);

namespace E98\YouTrack\Data;

use Carbon\CarbonImmutable;
use E98\YouTrack\Support\Time;

final readonly class Attachment
{
    public function __construct(
        public string $id,
        public string $name,
        public ?int $size,
        public ?string $mimeType,
        public ?string $url,
        public ?User $author,
        public ?CarbonImmutable $created,
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
            isset($data['size']) ? (int) $data['size'] : null,
            $data['mimeType'] ?? null,
            $data['url'] ?? null,
            User::fromArray($data['author'] ?? null),
            Time::fromMillis($data['created'] ?? null),
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
            'size' => $this->size,
            'mimeType' => $this->mimeType,
            'url' => $this->url,
            'author' => $this->author?->toArray(),
            'created' => $this->created?->toIso8601String(),
        ];
    }
}
