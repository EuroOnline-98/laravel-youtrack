<?php

declare(strict_types=1);

namespace E98\YouTrack\Testing;

use E98\YouTrack\Data\Project;
use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Resources\Projects;
use E98\YouTrack\YouTrackClient;
use Illuminate\Support\Collection;

/**
 * In-memory stand-in for the projects resource. The configured default project
 * is registered automatically; add others with YouTrack::fake()->withProject().
 */
final class FakeProjects extends Projects
{
    /** @var array<string, Project> Keyed by lower-case short name. */
    private array $projects = [];

    private int $sequence = 0;

    public function __construct(YouTrackClient $client)
    {
        parent::__construct($client);

        $default = $client->option('default_project');
        if (is_string($default) && trim($default) !== '' && preg_match('/^\d+-\d+$/', $default) !== 1) {
            $this->register($default);
        }
    }

    /**
     * Register a project (or return the one already registered under that short name).
     */
    public function register(string $shortName, ?string $name = null): Project
    {
        $key = strtolower($shortName);

        return $this->projects[$key] ??= new Project('0-' . ++$this->sequence, $shortName, $name ?? $shortName, false);
    }

    /**
     * @return Collection<int, Project>
     */
    public function all(int $limit = 1000): Collection
    {
        return collect(array_values($this->projects))->take($limit)->values();
    }

    public function find(string $reference): Project
    {
        $needle = strtolower($reference);

        foreach ($this->projects as $project) {
            if (strtolower($project->shortName) === $needle || $project->id === $reference) {
                return $project;
            }
        }

        throw new NotFoundException(
            "YouTrack project '{$reference}' was not found. Register it with YouTrack::fake()->withProject('{$reference}').",
            404,
        );
    }

    public function resolveId(string $reference): string
    {
        if (preg_match('/^\d+-\d+$/', $reference) === 1) {
            return $reference;
        }

        return $this->find($reference)->id;
    }
}
