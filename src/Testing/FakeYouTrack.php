<?php

declare(strict_types=1);

namespace E98\YouTrack\Testing;

use Closure;
use E98\YouTrack\Exceptions\YouTrackException;
use E98\YouTrack\Support\FieldValue;
use E98\YouTrack\YouTrackClient;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Assert;

/**
 * A recording, in-memory replacement for the YouTrack client, installed with
 * YouTrack::fake(). Nothing leaves the process: issues, comments and projects live
 * in memory, every call is recorded, and the assert* methods check what your code did.
 *
 *     $fake = YouTrack::fake();
 *     $fake->withIssue('SUP-12', ['state' => 'Open']);
 *
 *     // ... run the code under test ...
 *
 *     $fake->assertIssueCreated(['summary' => 'Checkout fails', 'project' => 'SUP']);
 *     $fake->assertCommented('SUP-12');
 *
 * Calls that bypass the resources (get/post/request/upload on the client) are not
 * faked and throw, so a test can never reach a real server by accident.
 */
final class FakeYouTrack extends YouTrackClient
{
    private const DEFAULT_RESOLVED_STATES = [
        'Fixed', 'Verified', "Won't fix", 'Duplicate', 'Obsolete', "Can't reproduce", 'Incomplete',
    ];

    private readonly FakeProjects $fakeProjects;

    private readonly FakeTags $fakeTags;

    private readonly FakeIssues $fakeIssues;

    /** @var list<array{type: string, data: array<string, mixed>}> */
    private array $recorded = [];

    private string $initialState = 'Submitted';

    /** @var list<string> */
    private array $resolvedStates = self::DEFAULT_RESOLVED_STATES;

    /**
     * @param  array<string, mixed>  $config  The "youtrack" config array.
     */
    public function __construct(array $config = [])
    {
        if (trim((string) ($config['base_url'] ?? '')) === '') {
            $config['base_url'] = 'https://youtrack.fake';
        }

        parent::__construct($config);

        $this->fakeProjects = new FakeProjects($this);
        $this->fakeTags = new FakeTags($this);
        $this->fakeIssues = new FakeIssues($this, $this->fakeProjects, $this->fakeTags);
    }

    public function issues(): FakeIssues
    {
        return $this->fakeIssues;
    }

    public function projects(): FakeProjects
    {
        return $this->fakeProjects;
    }

    public function tags(): FakeTags
    {
        return $this->fakeTags;
    }

    /**
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        return $this->user() + ['email' => 'fake@youtrack.test'];
    }

    /**
     * @return array<string, mixed>
     */
    public function request(string $method, string $uri, array $query = [], ?array $body = null): mixed
    {
        throw $this->unexpected(strtoupper($method) . ' ' . $uri);
    }

    public function upload(string $uri, string $path, ?string $name = null, array $query = []): mixed
    {
        throw $this->unexpected('POST ' . $uri . ' (upload)');
    }

    public function fetch(string $url, array $options = []): Response
    {
        throw $this->unexpected('GET ' . $url . ' (file download)');
    }

    // ---------------------------------------------------------------- setup

    /**
     * Seed an issue that already "exists in YouTrack", e.g. one your database stores the id of.
     *
     * @param  array{summary?: string, description?: string|null, state?: string, project?: string, customFields?: list<array<string, mixed>>}  $attributes
     */
    public function withIssue(string $key, array $attributes = []): self
    {
        $this->fakeIssues->seed($key, $attributes);

        return $this;
    }

    public function withComment(string $issue, string $text, ?string $author = null): self
    {
        $this->fakeIssues->seedComment($issue, $text, $author);

        return $this;
    }

    /**
     * Put a file on an issue that already exists in the fake, so your code can list and download it.
     */
    public function withAttachment(string $issue, string $name, string $contents = '', ?string $mimeType = null): self
    {
        $this->fakeIssues->seedAttachment($issue, $name, $contents, $mimeType);

        return $this;
    }

    /**
     * A tag that already exists in YouTrack (and is visible to the token's user).
     */
    public function withTag(string $name): self
    {
        $this->fakeTags->register($name);

        return $this;
    }

    /**
     * Tag an issue that already exists in the fake; the tag is registered if needed.
     */
    public function withTagOn(string $issue, string $tag): self
    {
        $this->fakeIssues->seedTag($issue, $tag);

        return $this;
    }

    /**
     * Make a user watch an issue that already exists in the fake.
     */
    public function withWatcher(string $issue, string $login, bool $starred = true): self
    {
        $this->fakeIssues->seedWatcher($issue, $login, $starred);

        return $this;
    }

    /**
     * Link two issues that already exist in the fake; the phrase says how $issue relates to $target.
     *
     *     $fake->withLink('SUP-2', 'subtask of', 'SUP-1');
     */
    public function withLink(string $issue, string $phrase, string $target): self
    {
        $this->fakeIssues->seedLink($issue, $phrase, $target);

        return $this;
    }

    public function withProject(string $shortName, ?string $name = null): self
    {
        $this->fakeProjects->register($shortName, $name);

        return $this;
    }

    /**
     * Replace the plain text search, e.g. to answer a specific YouTrack query.
     *
     * @param  Closure(string, int, int): iterable<\E98\YouTrack\Data\Issue>  $resolver  Receives the query, limit and skip.
     */
    public function searchUsing(Closure $resolver): self
    {
        $this->fakeIssues->searchUsing($resolver);

        return $this;
    }

    /**
     * The state new issues start in (YouTrack's default is "Submitted").
     */
    public function initialStateIs(string $state): self
    {
        $this->initialState = $state;

        return $this;
    }

    /**
     * The states that mark an issue as resolved (Issue::isResolved()).
     *
     * @param  list<string>  $states
     */
    public function resolvedStatesAre(array $states): self
    {
        $this->resolvedStates = array_values($states);

        return $this;
    }

    public function initialState(): string
    {
        return $this->initialState;
    }

    public function isResolvedState(string $state): bool
    {
        return in_array($state, $this->resolvedStates, true);
    }

    /**
     * @return array{id: string, login: string, fullName: string}
     */
    public function user(): array
    {
        return ['id' => '1-0', 'login' => 'fake', 'fullName' => 'Fake User'];
    }

    // ------------------------------------------------------------ recording

    /**
     * @internal Used by the fake resources.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(string $type, array $data): void
    {
        $this->recorded[] = ['type' => $type, 'data' => $data];
    }

    /**
     * The recorded calls (type: create, get, search, update, delete, comments, comment, command, attach,
     * attachments, deleteAttachment, download, tags, addTag, removeTag, listTags, createTag, watchers,
     * addWatcher, removeWatcher, linkTypes, links, link, unlink).
     *
     * @return list<array<string, mixed>> The data of each call, in the order they happened.
     */
    public function recorded(?string $type = null): array
    {
        $calls = $type === null
            ? $this->recorded
            : array_filter($this->recorded, static fn (array $call): bool => $call['type'] === $type);

        return array_values(array_map(static fn (array $call): array => $call['data'], $calls));
    }

    // ----------------------------------------------------------- assertions

    /**
     * Assert that an issue was created. Pass an array to match on summary, description,
     * project (short name) and "fields" (custom field name => plain value), or a closure
     * that receives the recorded call (summary, description, project, customFields, fields,
     * extra) and returns true for a match.
     *
     *     $fake->assertIssueCreated(['summary' => 'Checkout fails', 'fields' => ['Priority' => 'Critical']]);
     *
     * @param  array<string, mixed>|Closure(array<string, mixed>): bool|null  $expected
     */
    public function assertIssueCreated(array|Closure|null $expected = null): void
    {
        $created = $this->recorded('create');
        Assert::assertNotEmpty($created, 'Expected an issue to be created, but none was.');

        if ($expected === null) {
            return;
        }

        $matches = array_filter($created, fn (array $call): bool => $this->matchesCreate($call, $expected));

        Assert::assertNotEmpty(
            $matches,
            'No created issue matched the expectation. Created: ' . $this->dump($created),
        );
    }

    public function assertIssueCreatedTimes(int $times): void
    {
        Assert::assertCount($times, $this->recorded('create'), "Expected {$times} issue(s) to be created.");
    }

    public function assertNoIssueCreated(): void
    {
        $created = $this->recorded('create');

        Assert::assertEmpty($created, 'Expected no issue to be created, but got: ' . $this->dump($created));
    }

    /**
     * Assert that a comment was added to the issue; optionally with exactly this text,
     * or accepted by a closure that receives the text.
     *
     * @param  string|Closure(string): bool|null  $text
     */
    public function assertCommented(string $issue, string|Closure|null $text = null): void
    {
        $comments = array_filter(
            $this->recorded('comment'),
            fn (array $call): bool => strcasecmp((string) $call['id'], $issue) === 0,
        );

        Assert::assertNotEmpty($comments, "Expected a comment on {$issue}, but none was added.");

        if ($text === null) {
            return;
        }

        $matches = array_filter($comments, static fn (array $call): bool => $text instanceof Closure
            ? (bool) $text((string) $call['text'])
            : $call['text'] === $text);

        Assert::assertNotEmpty($matches, "No comment on {$issue} matched. Comments: " . $this->dump(array_values($comments)));
    }

    public function assertNothingCommented(): void
    {
        $comments = $this->recorded('comment');

        Assert::assertEmpty($comments, 'Expected no comments, but got: ' . $this->dump($comments));
    }

    /**
     * Assert that the issue's state was changed (changeState() or an update of the state
     * field); optionally to this state.
     */
    public function assertStateChanged(string $issue, ?string $state = null): void
    {
        $field = (string) $this->option('state_field', 'State');

        $changes = array_filter($this->recorded('update'), static function (array $call) use ($issue, $field, $state): bool {
            if (strcasecmp((string) $call['id'], $issue) !== 0) {
                return false;
            }

            foreach ((array) ($call['attributes']['customFields'] ?? []) as $payload) {
                if (($payload['name'] ?? null) === $field
                    && ($state === null || FieldValue::normalize($payload['value'] ?? null) === $state)) {
                    return true;
                }
            }

            return false;
        });

        Assert::assertNotEmpty(
            $changes,
            $state === null
                ? "Expected the state of {$issue} to be changed, but it was not."
                : "Expected {$issue} to be moved to '{$state}', but it was not.",
        );
    }

    /**
     * Assert that the issue was updated (summary, description, custom fields ...); optionally
     * with a closure that receives the update attributes and returns true for a match.
     *
     * @param  Closure(array<string, mixed>): bool|null  $callback
     */
    public function assertUpdated(string $issue, ?Closure $callback = null): void
    {
        $updates = array_filter(
            $this->recorded('update'),
            fn (array $call): bool => strcasecmp((string) $call['id'], $issue) === 0
                && ($callback === null || (bool) $callback($call['attributes'])),
        );

        Assert::assertNotEmpty($updates, "Expected {$issue} to be updated, but it was not.");
    }

    public function assertCommandSent(string $issue, ?string $query = null): void
    {
        $commands = array_filter(
            $this->recorded('command'),
            static fn (array $call): bool => strcasecmp((string) $call['id'], $issue) === 0
                && ($query === null || $call['query'] === $query),
        );

        Assert::assertNotEmpty(
            $commands,
            $query === null
                ? "Expected a command for {$issue}, but none was sent."
                : "Expected the command '{$query}' for {$issue}, but it was not sent.",
        );
    }

    public function assertAttached(string $issue, ?string $name = null): void
    {
        $attachments = array_filter(
            $this->recorded('attach'),
            static fn (array $call): bool => strcasecmp((string) $call['id'], $issue) === 0
                && ($name === null || $call['name'] === $name),
        );

        Assert::assertNotEmpty($attachments, "Expected a file to be attached to {$issue}, but none was.");
    }

    /**
     * Assert that an attachment was deleted from the issue; optionally by its name or id.
     */
    public function assertAttachmentDeleted(string $issue, ?string $nameOrId = null): void
    {
        $deleted = array_filter(
            $this->recorded('deleteAttachment'),
            static fn (array $call): bool => strcasecmp((string) $call['id'], $issue) === 0
                && ($nameOrId === null || $call['name'] === $nameOrId || $call['attachment'] === $nameOrId),
        );

        Assert::assertNotEmpty($deleted, "Expected an attachment to be deleted from {$issue}, but none was.");
    }

    /**
     * Assert that an attachment was downloaded (contents, file or stream); optionally by its name or id.
     */
    public function assertAttachmentDownloaded(?string $nameOrId = null): void
    {
        $downloads = array_filter(
            $this->recorded('download'),
            static fn (array $call): bool => $nameOrId === null || $call['name'] === $nameOrId || $call['attachment'] === $nameOrId,
        );

        Assert::assertNotEmpty($downloads, 'Expected an attachment to be downloaded, but none was.');
    }

    /**
     * Assert that a tag was added to the issue; optionally the tag with this name.
     */
    public function assertTagged(string $issue, ?string $tag = null): void
    {
        $this->assertTagCall('addTag', $issue, $tag, "Expected {$issue} to be tagged" . ($tag === null ? '' : " with '{$tag}'") . ', but it was not.');
    }

    /**
     * Assert that a tag was removed from the issue; optionally the tag with this name.
     */
    public function assertUntagged(string $issue, ?string $tag = null): void
    {
        $this->assertTagCall('removeTag', $issue, $tag, "Expected a tag to be removed from {$issue}" . ($tag === null ? '' : " ('{$tag}')") . ', but it was not.');
    }

    /**
     * Assert that a tag was created (not merely used); optionally with this name.
     */
    public function assertTagCreated(?string $name = null): void
    {
        $created = array_filter(
            $this->recorded('createTag'),
            static fn (array $call): bool => $name === null || strcasecmp((string) $call['name'], $name) === 0,
        );

        Assert::assertNotEmpty($created, 'Expected a tag to be created' . ($name === null ? '' : " ('{$name}')") . ', but none was.');
    }

    /**
     * Assert that a user was made a watcher of the issue; optionally this login.
     */
    public function assertWatcherAdded(string $issue, ?string $login = null): void
    {
        $this->assertWatcherCall('addWatcher', $issue, $login, "Expected a watcher to be added to {$issue}" . ($login === null ? '' : " ('{$login}')") . ', but none was.');
    }

    public function assertWatcherRemoved(string $issue, ?string $login = null): void
    {
        $this->assertWatcherCall('removeWatcher', $issue, $login, "Expected a watcher to be removed from {$issue}" . ($login === null ? '' : " ('{$login}')") . ', but none was.');
    }

    /**
     * Assert that the issue was linked to another one; optionally with this phrase ("subtask of")
     * and/or this target.
     */
    public function assertLinked(string $issue, ?string $phrase = null, ?string $target = null): void
    {
        $this->assertLinkCall('link', $issue, $phrase, $target, "Expected {$issue} to be linked" . ($phrase === null ? '' : " ('{$phrase}')") . ($target === null ? '' : " to {$target}") . ', but it was not.');
    }

    public function assertUnlinked(string $issue, ?string $phrase = null, ?string $target = null): void
    {
        $this->assertLinkCall('unlink', $issue, $phrase, $target, "Expected a link of {$issue} to be removed" . ($phrase === null ? '' : " ('{$phrase}')") . ($target === null ? '' : " to {$target}") . ', but it was not.');
    }

    public function assertDeleted(string $issue): void
    {
        $deleted = array_filter(
            $this->recorded('delete'),
            static fn (array $call): bool => strcasecmp((string) $call['id'], $issue) === 0,
        );

        Assert::assertNotEmpty($deleted, "Expected {$issue} to be deleted, but it was not.");
    }

    /**
     * Assert that the code under test made no call to YouTrack at all (reads included).
     */
    public function assertNothingSent(): void
    {
        $calls = $this->recorded();

        Assert::assertEmpty($calls, 'Expected nothing to be sent to YouTrack, but got ' . count($calls) . ' call(s): ' . $this->dump($calls));
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $call
     * @param  array<string, mixed>|Closure  $expected
     */
    private function matchesCreate(array $call, array|Closure $expected): bool
    {
        if ($expected instanceof Closure) {
            return (bool) $expected($call);
        }

        foreach ($expected as $key => $value) {
            if ($key === 'fields') {
                foreach ((array) $value as $name => $fieldValue) {
                    if (! array_key_exists($name, $call['fields']) || $call['fields'][$name] != $fieldValue) {
                        return false;
                    }
                }

                continue;
            }

            if ($key === 'project') {
                if (strcasecmp((string) $call['project'], (string) $value) !== 0) {
                    return false;
                }

                continue;
            }

            if (($call[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function assertTagCall(string $type, string $issue, ?string $tag, string $message): void
    {
        $calls = array_filter(
            $this->recorded($type),
            static fn (array $call): bool => strcasecmp((string) $call['id'], $issue) === 0
                && ($tag === null || strcasecmp((string) $call['tag'], $tag) === 0 || $call['tagId'] === $tag),
        );

        Assert::assertNotEmpty($calls, $message);
    }

    private function assertLinkCall(string $type, string $issue, ?string $phrase, ?string $target, string $message): void
    {
        $calls = array_filter(
            $this->recorded($type),
            static fn (array $call): bool => strcasecmp((string) $call['id'], $issue) === 0
                && ($phrase === null || strcasecmp((string) $call['phrase'], $phrase) === 0)
                && ($target === null || strcasecmp((string) $call['target'], $target) === 0),
        );

        Assert::assertNotEmpty($calls, $message);
    }

    private function assertWatcherCall(string $type, string $issue, ?string $login, string $message): void
    {
        $calls = array_filter(
            $this->recorded($type),
            static fn (array $call): bool => strcasecmp((string) $call['id'], $issue) === 0
                && ($login === null || $call['login'] === $login),
        );

        Assert::assertNotEmpty($calls, $message);
    }

    private function unexpected(string $request): YouTrackException
    {
        return new YouTrackException(
            "Unexpected request while YouTrack is faked: {$request}. "
            . 'Only the issues() and projects() resources are faked; the raw HTTP methods of the client are not.',
        );
    }

    private function dump(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
