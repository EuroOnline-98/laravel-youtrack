<?php

declare(strict_types=1);

namespace E98\YouTrack\Testing;

use E98\YouTrack\Data\Tag;
use E98\YouTrack\Data\User;
use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Resources\Tags;
use Illuminate\Support\Collection;

/**
 * In-memory stand-in for the tags resource. Tags that exist in YouTrack have to be
 * registered with YouTrack::fake()->withTag(), or created through the code under test.
 */
final class FakeTags extends Tags
{
    /** @var array<string, Tag> Keyed by lower-case name. */
    private array $tags = [];

    private int $sequence = 0;

    public function __construct(private readonly FakeYouTrack $fake)
    {
        parent::__construct($fake);
    }

    /**
     * Register a tag (or return the one already registered under that name). No call is recorded.
     */
    public function register(string $name): Tag
    {
        return $this->tags[mb_strtolower($name)] ??= new Tag(
            '6-' . ++$this->sequence,
            $name,
            false,
            User::fromArray($this->fake->user()),
        );
    }

    /**
     * @return Collection<int, Tag>
     */
    public function all(int $limit = 1000): Collection
    {
        $this->fake->record('listTags', []);

        return $this->registered()->take($limit)->values();
    }

    /**
     * The registered tags, without recording a call.
     *
     * @return Collection<int, Tag>
     */
    public function registered(): Collection
    {
        return collect(array_values($this->tags));
    }

    public function find(string $reference): Tag
    {
        $needle = mb_strtolower($reference);

        foreach ($this->tags as $tag) {
            if ($tag->id === $reference || mb_strtolower($tag->name) === $needle) {
                return $tag;
            }
        }

        throw new NotFoundException(
            "YouTrack tag '{$reference}' was not found. Register it with YouTrack::fake()->withTag('{$reference}').",
            404,
        );
    }

    public function create(string $name): Tag
    {
        $this->fake->record('createTag', ['name' => $name]);

        return $this->register($name);
    }
}
