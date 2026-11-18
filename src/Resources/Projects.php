<?php

declare(strict_types=1);

namespace E98\YouTrack\Resources;

use E98\YouTrack\Data\Project;
use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Fields;
use E98\YouTrack\YouTrackClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class Projects
{
    public function __construct(private readonly YouTrackClient $client)
    {
    }

    /**
     * @return Collection<int, Project>
     */
    public function all(int $limit = 1000): Collection
    {
        $data = $this->client->get('admin/projects', [
            'fields' => Fields::PROJECT,
            '$top' => $limit,
        ]);

        return collect($data ?? [])->map(fn (array $p): Project => Project::fromArray($p))->values();
    }

    /**
     * Find a project by short name ("SUP") or database id ("0-3").
     *
     * @throws NotFoundException
     */
    public function find(string $reference): Project
    {
        $needle = strtolower($reference);

        $project = $this->all()->first(
            fn (Project $p): bool => strtolower($p->shortName) === $needle || $p->id === $reference,
        );

        return $project ?? throw new NotFoundException("YouTrack project '{$reference}' was not found.", 404);
    }

    /**
     * Resolve a short name to the database id the API needs. Ids pass through
     * untouched; short names are looked up once and cached.
     *
     * @throws NotFoundException
     */
    public function resolveId(string $reference): string
    {
        if (preg_match('/^\d+-\d+$/', $reference) === 1) {
            return $reference;
        }

        $lookup = fn (): string => $this->find($reference)->id;
        $ttl = (int) $this->client->option('cache_ttl', 0);

        if ($ttl <= 0) {
            return $lookup();
        }

        $key = 'youtrack:project-id:' . md5($this->client->baseUrl()) . ':' . strtolower($reference);

        return Cache::remember($key, $ttl, $lookup);
    }
}
