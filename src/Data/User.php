<?php

declare(strict_types=1);

namespace E98\YouTrack\Data;

final readonly class User
{
    public function __construct(
        public ?string $id,
        public ?string $login,
        public ?string $fullName,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || $data === []) {
            return null;
        }

        return new self(
            $data['id'] ?? null,
            $data['login'] ?? null,
            $data['fullName'] ?? null,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'login' => $this->login, 'fullName' => $this->fullName];
    }
}
