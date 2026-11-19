<?php

declare(strict_types=1);

namespace E98\YouTrack\Data;

/**
 * A user who gets notifications about an issue. `starred` is true when the user
 * added the "Star" tag; other watchers follow the issue for other reasons
 * (reporter, assignee, commenter ...) and cannot be removed with unstar.
 */
final readonly class Watcher
{
    public function __construct(
        public ?User $user,
        public bool $starred,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            User::fromArray($data['user'] ?? null),
            (bool) ($data['isStarred'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['user' => $this->user?->toArray(), 'starred' => $this->starred];
    }
}
