<?php

declare(strict_types=1);

namespace E98\YouTrack\Resources;

use E98\YouTrack\Data\Tag;
use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Fields;
use E98\YouTrack\YouTrackClient;
use Illuminate\Support\Collection;

/**
 * Tags are private to their owner (the token's user) unless they are shared in
 * YouTrack, so these calls only see the tags that user can see and use.
 */
class Tags
{
    public function __construct(private readonly YouTrackClient $client)
    {
    }

    /**
     * @return Collection<int, Tag>
     */
    public function all(int $limit = 1000): Collection
    {
        $data = $this->client->get('tags', ['fields' => Fields::TAG, '$top' => $limit]);

        return collect($data ?? [])->map(fn (array $t): Tag => Tag::fromArray($t))->values();
    }

    /**
     * Find a tag by name (case-insensitive) or database id ("6-4").
     *
     * @throws NotFoundException
     */
    public function find(string $reference): Tag
    {
        $needle = mb_strtolower($reference);

        $tag = $this->all()->first(
            fn (Tag $t): bool => $t->id === $reference || mb_strtolower($t->name) === $needle,
        );

        return $tag ?? throw new NotFoundException(
            "YouTrack tag '{$reference}' was not found. Tags are private to their owner unless shared.",
            404,
        );
    }

    /**
     * Create a tag owned by the token's user. Needs the permission to create tags.
     */
    public function create(string $name): Tag
    {
        $data = $this->client->post('tags', ['name' => $name], ['fields' => Fields::TAG]);

        return Tag::fromArray((array) $data);
    }

    /**
     * Find a tag, or create it when $create is true and it does not exist.
     *
     * @throws NotFoundException
     */
    public function resolve(string $reference, bool $create = false): Tag
    {
        try {
            return $this->find($reference);
        } catch (NotFoundException $e) {
            if ($create && preg_match('/^\d+-\d+$/', $reference) !== 1) {
                return $this->create($reference);
            }

            throw $e;
        }
    }
}
