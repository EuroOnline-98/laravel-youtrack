<?php

declare(strict_types=1);

namespace E98\YouTrack\Resources;

use E98\YouTrack\CustomField;
use E98\YouTrack\Data\Comment;
use E98\YouTrack\Data\Issue;
use E98\YouTrack\Exceptions\ConfigurationException;
use E98\YouTrack\Fields;
use E98\YouTrack\YouTrackClient;
use Illuminate\Support\Collection;

class Issues
{
    public function __construct(
        private readonly YouTrackClient $client,
        private readonly Projects $projects,
    ) {
    }

    /**
     * Create an issue.
     *
     *     YouTrack::issues()->create(
     *         summary: 'Checkout fails',
     *         description: 'Stack trace ...',
     *         project: 'SUP',
     *         customFields: [CustomField::enum('Priority', 'Critical')],
     *     );
     *
     * @param  string|null  $project  Short name or id; falls back to youtrack.default_project.
     * @param  list<array<string, mixed>>  $customFields  Build them with CustomField::*().
     * @param  array<string, mixed>  $extra  Extra top-level payload attributes, merged over the defaults.
     */
    public function create(
        string $summary,
        ?string $description = null,
        ?string $project = null,
        array $customFields = [],
        array $extra = [],
    ): Issue {
        $project ??= $this->client->option('default_project');
        if (! is_string($project) || trim($project) === '') {
            throw new ConfigurationException('No project given and youtrack.default_project (YOUTRACK_PROJECT) is not set.');
        }

        $body = ['summary' => $summary];
        if ($description !== null) {
            $body['description'] = $description;
        }
        $body['project'] = ['id' => $this->projects->resolveId($project)];
        if ($customFields !== []) {
            $body['customFields'] = array_values($customFields);
        }

        $data = $this->client->post('issues', $extra + $body, ['fields' => Fields::ISSUE]);

        return $this->hydrate($data);
    }

    /**
     * Fetch one issue by readable id ("SUP-123") or database id ("2-45").
     *
     * @throws \E98\YouTrack\Exceptions\NotFoundException
     */
    public function get(string $id): Issue
    {
        $data = $this->client->get('issues/' . rawurlencode($id), ['fields' => Fields::ISSUE]);

        return $this->hydrate($data);
    }

    /**
     * Search with YouTrack's query language, e.g. "project: SUP #Unresolved".
     *
     * @return Collection<int, Issue>
     */
    public function search(string $query = '', int $limit = 42, int $skip = 0): Collection
    {
        $params = ['fields' => Fields::ISSUE, '$top' => $limit, '$skip' => $skip];
        if ($query !== '') {
            $params['query'] = $query;
        }

        $data = $this->client->get('issues', $params);

        return collect($data ?? [])->map(fn (array $i): Issue => $this->hydrate($i))->values();
    }

    /**
     * The current status (value of the configured state field) of an issue.
     */
    public function state(string $id): ?string
    {
        return $this->get($id)->state;
    }

    /**
     * Update attributes: summary, description, customFields, ...
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $id, array $attributes): Issue
    {
        $data = $this->client->post('issues/' . rawurlencode($id), $attributes, ['fields' => Fields::ISSUE]);

        return $this->hydrate($data);
    }

    /**
     * Move the issue to another state, e.g. "In Progress" or "Fixed".
     * The state must be a valid value of the project's state bundle.
     */
    public function changeState(string $id, string $state): Issue
    {
        $field = (string) $this->client->option('state_field', 'State');

        return $this->update($id, ['customFields' => [CustomField::state($field, $state)]]);
    }

    public function delete(string $id): void
    {
        $this->client->delete('issues/' . rawurlencode($id));
    }

    /**
     * @return Collection<int, Comment>
     */
    public function comments(string $id, int $limit = 100, int $skip = 0): Collection
    {
        $data = $this->client->get('issues/' . rawurlencode($id) . '/comments', [
            'fields' => Fields::COMMENT,
            '$top' => $limit,
            '$skip' => $skip,
        ]);

        return collect($data ?? [])->map(fn (array $c): Comment => Comment::fromArray($c))->values();
    }

    public function comment(string $id, string $text): Comment
    {
        $data = $this->client->post(
            'issues/' . rawurlencode($id) . '/comments',
            ['text' => $text],
            ['fields' => Fields::COMMENT],
        );

        return Comment::fromArray($data);
    }

    /**
     * Apply a YouTrack command, e.g. "for anna State Fixed".
     * Useful for changes that are awkward to express as field payloads.
     */
    public function command(string $id, string $query, ?string $comment = null): void
    {
        $ref = preg_match('/^\d+-\d+$/', $id) === 1 ? ['id' => $id] : ['idReadable' => $id];

        $body = ['query' => $query, 'issues' => [$ref]];
        if ($comment !== null) {
            $body['comment'] = $comment;
        }

        $this->client->post('commands', $body);
    }

    /**
     * Attach a local file to an issue.
     *
     * @return list<array<string, mixed>> The created attachment(s) as returned by YouTrack.
     */
    public function attach(string $id, string $path, ?string $name = null): array
    {
        $data = $this->client->upload(
            'issues/' . rawurlencode($id) . '/attachments',
            $path,
            $name,
            ['fields' => Fields::ATTACHMENT],
        );

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hydrate(mixed $data): Issue
    {
        return Issue::fromArray(
            (array) $data,
            $this->client->baseUrl(),
            (string) $this->client->option('state_field', 'State'),
        );
    }
}
