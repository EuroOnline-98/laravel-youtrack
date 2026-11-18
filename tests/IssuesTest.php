<?php

declare(strict_types=1);

namespace E98\YouTrack\Tests;

use E98\YouTrack\CustomField;
use E98\YouTrack\Exceptions\ConfigurationException;
use E98\YouTrack\Facades\YouTrack;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class IssuesTest extends TestCase
{
    public function test_create_resolves_project_short_name_and_sends_the_right_request(): void
    {
        Http::fake([
            'pm.test/api/admin/projects*' => Http::response($this->projectsPayload()),
            'pm.test/api/issues*' => Http::response($this->issuePayload()),
        ]);

        $issue = YouTrack::issues()->create(
            summary: 'Printer on fire',
            description: 'Smoke everywhere',
            project: 'sup',
            customFields: [CustomField::enum('Priority', 'Critical')],
        );

        Http::assertSent(function (Request $r): bool {
            if ($r->method() !== 'POST' || ! str_starts_with($r->url(), 'https://pm.test/api/issues?')) {
                return false;
            }

            return $r->hasHeader('Authorization', 'Bearer perm:test-token')
                && $r->hasHeader('Accept', 'application/json')
                && $r->data()['project'] === ['id' => '0-2']
                && $r->data()['summary'] === 'Printer on fire'
                && $r->data()['description'] === 'Smoke everywhere'
                && $r->data()['customFields'][0] === [
                    'name' => 'Priority',
                    '$type' => 'SingleEnumIssueCustomField',
                    'value' => ['name' => 'Critical'],
                ];
        });

        $this->assertSame('DEMO-12', $issue->key);
        $this->assertSame('https://pm.test/issue/DEMO-12', $issue->url);
    }

    public function test_create_uses_default_project_and_passes_numeric_ids_through(): void
    {
        Http::fake([
            'pm.test/api/admin/projects*' => Http::response($this->projectsPayload()),
            'pm.test/api/issues*' => Http::response($this->issuePayload()),
        ]);

        YouTrack::issues()->create('From default project');
        YouTrack::issues()->create('From explicit id', project: '0-2');

        $ids = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')
            ->map(fn (array $pair): string => $pair[0]->data()['project']['id'])
            ->values()
            ->all();

        $this->assertSame(['0-1', '0-2'], $ids);
    }

    public function test_project_id_lookup_is_cached(): void
    {
        Http::fake([
            'pm.test/api/admin/projects*' => Http::response($this->projectsPayload()),
            'pm.test/api/issues*' => Http::response($this->issuePayload()),
        ]);

        YouTrack::issues()->create('one', project: 'DEMO');
        YouTrack::issues()->create('two', project: 'DEMO');

        $lookups = Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/admin/projects'));

        $this->assertCount(1, $lookups);
    }

    public function test_create_without_any_project_fails_clearly(): void
    {
        config(['youtrack.default_project' => null]);

        $this->expectException(ConfigurationException::class);

        YouTrack::issues()->create('No project');
    }

    public function test_get_returns_a_hydrated_issue(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12*' => Http::response($this->issuePayload())]);

        $issue = YouTrack::issues()->get('DEMO-12');

        $this->assertSame('2-45', $issue->id);
        $this->assertSame('DEMO-12', $issue->key);
        $this->assertSame('Printer on fire', $issue->summary);
        $this->assertSame('DEMO', $issue->project);
        $this->assertSame('Open', $issue->state);
        $this->assertFalse($issue->isResolved());
        $this->assertNull($issue->resolvedAt);
        $this->assertSame('anna', $issue->reporter?->login);
        $this->assertSame('bela', $issue->assignee());
        $this->assertSame('Major', $issue->field('Priority'));
        $this->assertSame(1700000000, $issue->created?->getTimestamp());
        $this->assertSame('https://pm.test/issue/DEMO-12', $issue->url);
    }

    public function test_resolved_issue_is_flagged(): void
    {
        Http::fake(['pm.test/api/issues/*' => Http::response($this->issuePayload(['resolved' => 1700000200000]))]);

        $issue = YouTrack::issues()->get('DEMO-12');

        $this->assertTrue($issue->isResolved());
        $this->assertSame(1700000200, $issue->resolvedAt?->getTimestamp());
    }

    public function test_state_returns_the_status_name(): void
    {
        Http::fake(['pm.test/api/issues/*' => Http::response($this->issuePayload())]);

        $this->assertSame('Open', YouTrack::issues()->state('DEMO-12'));
    }

    public function test_state_field_name_is_configurable(): void
    {
        config(['youtrack.state_field' => 'Status']);
        Http::fake(['pm.test/api/issues/*' => Http::response($this->issuePayload([
            'customFields' => [[
                '$type' => 'StateIssueCustomField',
                'name' => 'Status',
                'value' => ['name' => 'Waiting'],
            ]],
        ]))]);

        $this->assertSame('Waiting', YouTrack::issues()->state('DEMO-12'));
    }

    public function test_search_sends_query_and_paging_and_returns_a_collection(): void
    {
        Http::fake(['pm.test/api/issues*' => Http::response([
            $this->issuePayload(),
            $this->issuePayload(['id' => '2-46', 'idReadable' => 'DEMO-13']),
        ])]);

        $issues = YouTrack::issues()->search('project: DEMO #Unresolved', limit: 10, skip: 20);

        $this->assertCount(2, $issues);
        $this->assertSame(['DEMO-12', 'DEMO-13'], $issues->pluck('key')->all());

        Http::assertSent(function (Request $r): bool {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $r->method() === 'GET'
                && $q['query'] === 'project: DEMO #Unresolved'
                && $q['$top'] === '10'
                && $q['$skip'] === '20'
                && str_contains($q['fields'], 'idReadable');
        });
    }

    public function test_change_state_sends_a_typed_state_field(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12*' => Http::response($this->issuePayload())]);

        YouTrack::issues()->changeState('DEMO-12', 'In Progress');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_starts_with($r->url(), 'https://pm.test/api/issues/DEMO-12?')
            && $r->data() === ['customFields' => [[
                'name' => 'State',
                '$type' => 'StateIssueCustomField',
                'value' => ['name' => 'In Progress'],
            ]]]);
    }

    public function test_update_posts_the_given_attributes(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12*' => Http::response($this->issuePayload(['summary' => 'New title']))]);

        $issue = YouTrack::issues()->update('DEMO-12', ['summary' => 'New title']);

        $this->assertSame('New title', $issue->summary);
        Http::assertSent(fn (Request $r): bool => $r->data() === ['summary' => 'New title']);
    }

    public function test_delete_sends_a_delete_request(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12*' => Http::response('', 200)]);

        YouTrack::issues()->delete('DEMO-12');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && str_starts_with($r->url(), 'https://pm.test/api/issues/DEMO-12'));
    }

    public function test_comments_can_be_listed_and_added(): void
    {
        $comment = [
            'id' => '4-1',
            'text' => 'Looks like a fuse',
            'created' => 1700000300000,
            'updated' => null,
            'author' => ['id' => '1-2', 'login' => 'bela', 'fullName' => 'Béla Nagy'],
        ];

        Http::fake([
            'pm.test/api/issues/DEMO-12/comments*' => Http::sequence()
                ->push([$comment])
                ->push($comment),
        ]);

        $list = YouTrack::issues()->comments('DEMO-12');
        $this->assertCount(1, $list);
        $this->assertSame('Looks like a fuse', $list[0]->text);
        $this->assertSame('bela', $list[0]->author?->login);

        $added = YouTrack::issues()->comment('DEMO-12', 'Looks like a fuse');
        $this->assertSame('4-1', $added->id);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r->data() === ['text' => 'Looks like a fuse']);
    }

    public function test_command_targets_the_issue_by_readable_id(): void
    {
        Http::fake(['pm.test/api/commands*' => Http::response('', 200)]);

        YouTrack::issues()->command('DEMO-12', 'for bela State Fixed', 'Done');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://pm.test/api/commands'
            && $r->data() === [
                'query' => 'for bela State Fixed',
                'issues' => [['idReadable' => 'DEMO-12']],
                'comment' => 'Done',
            ]);
    }

    public function test_attach_uploads_a_multipart_file(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12/attachments*' => Http::response([
            ['id' => '5-1', 'name' => 'log.txt', 'size' => 5, 'mimeType' => 'text/plain', 'url' => '/api/files/5-1'],
        ])]);

        $path = tempnam(sys_get_temp_dir(), 'yt');
        file_put_contents($path, 'hello');

        try {
            $attachments = YouTrack::issues()->attach('DEMO-12', $path, 'log.txt');
        } finally {
            @unlink($path);
        }

        $this->assertSame('log.txt', $attachments[0]['name']);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/issues/DEMO-12/attachments')
            && $r->isMultipart()
            && $r->data()[0]['name'] === 'file'
            && $r->data()[0]['filename'] === 'log.txt');
    }
}
