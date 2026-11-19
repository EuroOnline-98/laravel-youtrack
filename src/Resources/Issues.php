<?php

declare(strict_types=1);

namespace E98\YouTrack\Resources;

use E98\YouTrack\CustomField;
use E98\YouTrack\Data\Attachment;
use E98\YouTrack\Data\Comment;
use E98\YouTrack\Data\Issue;
use E98\YouTrack\Data\Tag;
use E98\YouTrack\Data\Watcher;
use E98\YouTrack\Exceptions\ConfigurationException;
use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Exceptions\YouTrackException;
use E98\YouTrack\Fields;
use E98\YouTrack\YouTrackClient;
use Illuminate\Support\Collection;
use Psr\Http\Message\StreamInterface;
use Throwable;

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
     * The files attached to an issue.
     *
     * @return Collection<int, Attachment>
     */
    public function attachments(string $id, int $limit = 100, int $skip = 0): Collection
    {
        $data = $this->client->get('issues/' . rawurlencode($id) . '/attachments', [
            'fields' => Fields::ATTACHMENT,
            '$top' => $limit,
            '$skip' => $skip,
        ]);

        return collect($data ?? [])->map(fn (array $a): Attachment => Attachment::fromArray($a))->values();
    }

    /**
     * Remove an attachment from an issue. Needs the permission to delete attachments.
     *
     * @param  Attachment|string  $attachment  The attachment or its id.
     */
    public function deleteAttachment(string $id, Attachment|string $attachment): void
    {
        $attachmentId = $attachment instanceof Attachment ? $attachment->id : $attachment;

        $this->client->delete('issues/' . rawurlencode($id) . '/attachments/' . rawurlencode($attachmentId));
    }

    /**
     * The content of an attachment as a string. Fine for small files; use
     * saveAttachment() or attachmentStream() for large ones.
     */
    public function attachmentContents(Attachment $attachment): string
    {
        return $this->client->fetch($this->downloadUrl($attachment))->body();
    }

    /**
     * Download an attachment into a file and return the path. The file is written to
     * "<path>.part" first, so a failed download never leaves a partial file at $path.
     *
     * @throws YouTrackException When the target directory does not exist or the download fails.
     */
    public function saveAttachment(Attachment $attachment, string $path): string
    {
        $url = $this->downloadUrl($attachment);

        $directory = dirname($path);
        if (! is_dir($directory)) {
            throw new YouTrackException("Cannot save the attachment: directory does not exist: {$directory}");
        }

        $partial = $path . '.part';

        try {
            $response = $this->client->fetch($url, ['sink' => $partial]);
            unset($response); // closes the file handle before the rename (matters on Windows)

            if (! @rename($partial, $path)) {
                throw new YouTrackException("Cannot save the attachment to {$path}.");
            }
        } catch (Throwable $e) {
            if (is_file($partial)) {
                @unlink($partial);
            }

            throw $e;
        }

        return $path;
    }

    /**
     * The content of an attachment as a PSR-7 stream, read while you consume it.
     */
    public function attachmentStream(Attachment $attachment): StreamInterface
    {
        return $this->client->fetch($this->downloadUrl($attachment), ['stream' => true])
            ->toPsrResponse()
            ->getBody();
    }

    /**
     * The tags on an issue that the token's user can see.
     *
     * @return Collection<int, Tag>
     */
    public function tags(string $id): Collection
    {
        $data = $this->client->get('issues/' . rawurlencode($id) . '/tags', ['fields' => Fields::TAG]);

        return collect($data ?? [])->map(fn (array $t): Tag => Tag::fromArray($t))->values();
    }

    /**
     * Tag an issue. The tag is given as a Tag, a name or an id ("6-4"). With $create the tag
     * is created (owned by the token's user) when no tag of that name exists.
     *
     * A name that looks like an id ("1-2") is taken as an id; pass a Tag object for such names.
     *
     * @throws \E98\YouTrack\Exceptions\NotFoundException When the tag does not exist (or is not visible).
     */
    public function addTag(string $id, Tag|string $tag, bool $create = false): Tag
    {
        $tag = $tag instanceof Tag ? $tag : $this->client->tags()->resolve($tag, $create);

        $this->client->post('issues/' . rawurlencode($id) . '/tags', ['id' => $tag->id], ['fields' => Fields::TAG]);

        return $tag;
    }

    /**
     * Remove a tag from an issue, given as a Tag, a name or an id.
     *
     * @throws NotFoundException When the issue does not carry such a tag.
     */
    public function removeTag(string $id, Tag|string $tag): void
    {
        if (is_string($tag) && preg_match('/^\d+-\d+$/', $tag) !== 1) {
            $needle = mb_strtolower($tag);
            $found = $this->tags($id)->first(fn (Tag $t): bool => mb_strtolower($t->name) === $needle);

            $tag = $found ?? throw new NotFoundException("Issue {$id} has no tag '{$tag}'.", 404);
        }

        $tagId = $tag instanceof Tag ? $tag->id : $tag;

        $this->client->delete('issues/' . rawurlencode($id) . '/tags/' . rawurlencode($tagId));
    }

    /**
     * The users watching an issue.
     *
     * @return Collection<int, Watcher>
     */
    public function watchers(string $id): Collection
    {
        $data = $this->client->get('issues/' . rawurlencode($id), ['fields' => Fields::WATCHERS]);
        $watchers = (array) (((array) $data)['watchers']['issueWatchers'] ?? []);

        return collect($watchers)->map(fn (array $w): Watcher => Watcher::fromArray($w))->values();
    }

    /**
     * Make a user watch the issue (adds the Star tag on their behalf, command "star <login>").
     */
    public function addWatcher(string $id, string $login): void
    {
        $this->command($id, 'star ' . $login);
    }

    /**
     * Stop a user from watching the issue (command "unstar <login>"). Users who follow the issue
     * for other reasons (reporter, assignee ...) keep getting notifications.
     */
    public function removeWatcher(string $id, string $login): void
    {
        $this->command($id, 'unstar ' . $login);
    }

    private function downloadUrl(Attachment $attachment): string
    {
        return $attachment->url
            ?? throw new YouTrackException("Attachment '{$attachment->name}' ({$attachment->id}) has no download URL.");
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
