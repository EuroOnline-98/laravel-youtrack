<?php

declare(strict_types=1);

namespace E98\YouTrack\Testing;

use Closure;
use E98\YouTrack\Data\Comment;
use E98\YouTrack\Data\Issue;
use E98\YouTrack\Data\Project;
use E98\YouTrack\Exceptions\ConfigurationException;
use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Resources\Issues;
use E98\YouTrack\Support\FieldValue;
use InvalidArgumentException;
use Illuminate\Support\Collection;

/**
 * In-memory stand-in for the issues resource: it keeps the issues you create or
 * seed, returns the same DTOs as the real one and records every call so the
 * assertions on FakeYouTrack can check what your code did. No HTTP is made.
 */
final class FakeIssues extends Issues
{
    /** @var array<string, array<string, mixed>> Raw API-shaped issues, keyed by upper-case readable id. */
    private array $issues = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $comments = [];

    /** @var array<string, int> Highest issue number per project short name. */
    private array $counters = [];

    private int $sequence = 0;

    private ?Closure $searchResolver = null;

    public function __construct(private readonly FakeYouTrack $fake, private readonly FakeProjects $fakeProjects)
    {
        parent::__construct($fake, $fakeProjects);
    }

    public function create(
        string $summary,
        ?string $description = null,
        ?string $project = null,
        array $customFields = [],
        array $extra = [],
    ): Issue {
        $project ??= $this->fake->option('default_project');
        if (! is_string($project) || trim($project) === '') {
            throw new ConfigurationException('No project given and youtrack.default_project (YOUTRACK_PROJECT) is not set.');
        }

        $projectModel = $this->projectFor($project);
        $customFields = array_values($customFields);

        $this->fake->record('create', [
            'summary' => $summary,
            'description' => $description,
            'project' => $projectModel->shortName,
            'customFields' => $customFields,
            'fields' => $this->fieldMap($customFields),
            'extra' => $extra,
        ]);

        $number = ($this->counters[$projectModel->shortName] ?? 0) + 1;
        $this->counters[$projectModel->shortName] = $number;
        $key = $projectModel->shortName . '-' . $number;

        $raw = $this->make($key, $projectModel, $summary, $description, $customFields);
        $this->issues[strtoupper($key)] = $raw;

        return $this->toIssue($raw);
    }

    public function get(string $id): Issue
    {
        $key = $this->locate($id);
        $this->fake->record('get', ['id' => $this->issues[$key]['idReadable']]);

        return $this->toIssue($this->issues[$key]);
    }

    /**
     * Without searchUsing() this is a plain case-insensitive text match on the id, summary
     * and description; YouTrack's query language is not interpreted.
     *
     * @return Collection<int, Issue>
     */
    public function search(string $query = '', int $limit = 42, int $skip = 0): Collection
    {
        $this->fake->record('search', ['query' => $query, 'limit' => $limit, 'skip' => $skip]);

        if ($this->searchResolver !== null) {
            return collect(($this->searchResolver)($query, $limit, $skip))->values();
        }

        $needle = mb_strtolower($query);

        return collect($this->issues)
            ->filter(fn (array $raw): bool => $needle === '' || str_contains(
                mb_strtolower($raw['idReadable'] . "\n" . $raw['summary'] . "\n" . (string) $raw['description']),
                $needle,
            ))
            ->slice($skip, $limit)
            ->map(fn (array $raw): Issue => $this->toIssue($raw))
            ->values();
    }

    public function update(string $id, array $attributes): Issue
    {
        $key = $this->locate($id);
        $raw = $this->issues[$key];

        $this->fake->record('update', ['id' => $raw['idReadable'], 'attributes' => $attributes]);

        foreach (['summary', 'description'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $raw[$field] = $attributes[$field];
            }
        }

        if (isset($attributes['customFields'])) {
            $raw = $this->applyCustomFields($raw, array_values((array) $attributes['customFields']));
        }

        $raw['updated'] = $this->now();
        $this->issues[$key] = $raw;

        return $this->toIssue($raw);
    }

    public function delete(string $id): void
    {
        $key = $this->locate($id);
        $this->fake->record('delete', ['id' => $this->issues[$key]['idReadable']]);

        unset($this->issues[$key], $this->comments[$key]);
    }

    /**
     * @return Collection<int, Comment>
     */
    public function comments(string $id, int $limit = 100, int $skip = 0): Collection
    {
        $key = $this->locate($id);
        $this->fake->record('comments', ['id' => $this->issues[$key]['idReadable']]);

        return collect(array_slice($this->comments[$key] ?? [], $skip, $limit))
            ->map(fn (array $c): Comment => Comment::fromArray($c))
            ->values();
    }

    public function comment(string $id, string $text): Comment
    {
        $key = $this->locate($id);
        $this->fake->record('comment', ['id' => $this->issues[$key]['idReadable'], 'text' => $text]);

        return Comment::fromArray($this->storeComment($key, $text, $this->fake->user()));
    }

    public function command(string $id, string $query, ?string $comment = null): void
    {
        $key = $this->locate($id);
        $this->fake->record('command', [
            'id' => $this->issues[$key]['idReadable'],
            'query' => $query,
            'comment' => $comment,
        ]);
    }

    /**
     * The file is not read, so the path does not have to exist in your test.
     *
     * @return list<array<string, mixed>>
     */
    public function attach(string $id, string $path, ?string $name = null): array
    {
        $key = $this->locate($id);
        $name ??= basename($path);

        $this->fake->record('attach', ['id' => $this->issues[$key]['idReadable'], 'path' => $path, 'name' => $name]);

        return [[
            'id' => '8-' . ++$this->sequence,
            'name' => $name,
            'size' => is_file($path) ? (int) filesize($path) : 0,
            'mimeType' => null,
            'url' => null,
        ]];
    }

    /**
     * Put an issue into the fake without recording a call, e.g. for tests that read or update it.
     *
     * @param  array{summary?: string, description?: string|null, state?: string, project?: string, customFields?: list<array<string, mixed>>}  $attributes
     */
    public function seed(string $key, array $attributes = []): Issue
    {
        if (preg_match('/^(.+)-(\d+)$/', $key, $m) !== 1) {
            throw new InvalidArgumentException("'{$key}' is not a readable issue id such as SUP-12.");
        }

        $project = $this->projectFor((string) ($attributes['project'] ?? $m[1]));
        $this->counters[$project->shortName] = max($this->counters[$project->shortName] ?? 0, (int) $m[2]);

        $customFields = array_values((array) ($attributes['customFields'] ?? []));
        if (isset($attributes['state'])) {
            $customFields[] = $this->stateField((string) $attributes['state']);
        }

        $raw = $this->make(
            $key,
            $project,
            (string) ($attributes['summary'] ?? "Seeded issue {$key}"),
            $attributes['description'] ?? null,
            $customFields,
        );
        $this->issues[strtoupper($key)] = $raw;

        return $this->toIssue($raw);
    }

    public function seedComment(string $id, string $text, ?string $author = null): void
    {
        $key = $this->locate($id);

        $this->storeComment(
            $key,
            $text,
            $author === null ? $this->fake->user() : ['id' => null, 'login' => $author, 'fullName' => $author],
        );
    }

    /**
     * Replace the default text search, e.g. to return issues for a specific YouTrack query.
     *
     * @param  Closure(string, int, int): iterable<Issue>  $resolver  Receives the query, limit and skip.
     */
    public function searchUsing(Closure $resolver): void
    {
        $this->searchResolver = $resolver;
    }

    /**
     * @param  list<array<string, mixed>>  $customFields
     * @return array<string, mixed>
     */
    private function make(string $key, Project $project, string $summary, ?string $description, array $customFields): array
    {
        $now = $this->now();

        $raw = [
            'id' => '2-' . ++$this->sequence,
            'idReadable' => $key,
            'summary' => $summary,
            'description' => $description,
            'created' => $now,
            'updated' => $now,
            'resolved' => null,
            'project' => ['id' => $project->id, 'shortName' => $project->shortName, 'name' => $project->name],
            'reporter' => $this->fake->user(),
            'customFields' => [$this->stateField($this->fake->initialState())],
        ];

        return $this->applyCustomFields($raw, $customFields);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<array<string, mixed>>  $payloads
     * @return array<string, mixed>
     */
    private function applyCustomFields(array $raw, array $payloads): array
    {
        foreach ($payloads as $payload) {
            $replaced = false;

            foreach ($raw['customFields'] as $i => $existing) {
                if (($existing['name'] ?? null) === ($payload['name'] ?? '')) {
                    $raw['customFields'][$i] = $payload;
                    $replaced = true;
                    break;
                }
            }

            if (! $replaced) {
                $raw['customFields'][] = $payload;
            }
        }

        $state = $this->stateOf($raw);
        $raw['resolved'] = $state !== null && $this->fake->isResolvedState($state)
            ? ($raw['resolved'] ?? $this->now())
            : null;

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function stateOf(array $raw): ?string
    {
        $field = (string) $this->fake->option('state_field', 'State');

        foreach ($raw['customFields'] as $existing) {
            if (($existing['name'] ?? null) === $field) {
                $value = FieldValue::normalize($existing['value'] ?? null);

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stateField(string $state): array
    {
        return [
            '$type' => 'StateIssueCustomField',
            'name' => (string) $this->fake->option('state_field', 'State'),
            'value' => ['$type' => 'StateBundleElement', 'name' => $state],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $customFields
     * @return array<string, mixed>
     */
    private function fieldMap(array $customFields): array
    {
        $map = [];
        foreach ($customFields as $payload) {
            $map[(string) ($payload['name'] ?? '')] = FieldValue::normalize($payload['value'] ?? null);
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $author
     * @return array<string, mixed>
     */
    private function storeComment(string $key, string $text, array $author): array
    {
        $now = $this->now();
        $comment = ['id' => '4-' . ++$this->sequence, 'text' => $text, 'author' => $author, 'created' => $now, 'updated' => $now];

        $this->comments[$key][] = $comment;

        return $comment;
    }

    private function projectFor(string $reference): Project
    {
        if (preg_match('/^\d+-\d+$/', $reference) === 1) {
            return $this->fakeProjects->find($reference);
        }

        return $this->fakeProjects->register($reference);
    }

    private function locate(string $id): string
    {
        $needle = strtoupper($id);
        if (isset($this->issues[$needle])) {
            return $needle;
        }

        foreach ($this->issues as $key => $raw) {
            if ($raw['id'] === $id) {
                return $key;
            }
        }

        throw new NotFoundException(
            "Issue '{$id}' does not exist in the fake. Create it first or seed it with YouTrack::fake()->withIssue('{$id}').",
            404,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function toIssue(array $raw): Issue
    {
        return Issue::fromArray(
            $raw,
            $this->fake->baseUrl(),
            (string) $this->fake->option('state_field', 'State'),
        );
    }

    private function now(): int
    {
        return (int) now()->format('Uv');
    }
}
