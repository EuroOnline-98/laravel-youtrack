<?php

declare(strict_types=1);

namespace E98\YouTrack\Tests;

use E98\YouTrack\Data\LinkType;
use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Exceptions\YouTrackException;
use E98\YouTrack\Facades\YouTrack;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class LinksTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function linkTypesPayload(): array
    {
        return [
            ['id' => '80-0', 'name' => 'Relates', 'sourceToTarget' => 'relates to', 'targetToSource' => '', 'directed' => false, 'aggregation' => false],
            ['id' => '80-1', 'name' => 'Depend', 'sourceToTarget' => 'is required for', 'targetToSource' => 'depends on', 'directed' => true, 'aggregation' => false],
            ['id' => '80-3', 'name' => 'Subtask', 'sourceToTarget' => 'parent for', 'targetToSource' => 'subtask of', 'directed' => true, 'aggregation' => true],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linksPayload(): array
    {
        $subtask = ['id' => '80-3', 'name' => 'Subtask', 'sourceToTarget' => 'parent for', 'targetToSource' => 'subtask of', 'directed' => true, 'aggregation' => true];
        $relates = ['id' => '80-0', 'name' => 'Relates', 'sourceToTarget' => 'relates to', 'targetToSource' => '', 'directed' => false, 'aggregation' => false];

        return [
            [
                'id' => '80-3s', 'direction' => 'OUTWARD', 'linkType' => $subtask,
                'issues' => [['id' => '2-60', 'idReadable' => 'DEMO-13', 'summary' => 'Child task', 'resolved' => null]],
            ],
            [
                'id' => '80-3t', 'direction' => 'INWARD', 'linkType' => $subtask,
                'issues' => [['id' => '2-61', 'idReadable' => 'DEMO-1', 'summary' => 'Epic', 'resolved' => 1700000200000]],
            ],
            [
                'id' => '80-0', 'direction' => 'BOTH', 'linkType' => $relates,
                'issues' => [
                    ['id' => '2-62', 'idReadable' => 'DEMO-7', 'summary' => 'Related one', 'resolved' => null],
                    ['id' => '2-63', 'idReadable' => 'DEMO-8', 'summary' => 'Related two', 'resolved' => null],
                ],
            ],
            [
                'id' => '80-1s', 'direction' => 'OUTWARD',
                'linkType' => ['id' => '80-1', 'name' => 'Depend', 'sourceToTarget' => 'is required for', 'targetToSource' => 'depends on', 'directed' => true, 'aggregation' => false],
                'issues' => [],
            ],
        ];
    }

    public function test_link_types_are_listed(): void
    {
        Http::fake(['pm.test/api/issueLinkTypes*' => Http::response($this->linkTypesPayload())]);

        $types = YouTrack::issues()->linkTypes();

        $this->assertCount(3, $types);
        $this->assertSame('Subtask', $types[2]->name);
        $this->assertSame('parent for', $types[2]->outward);
        $this->assertSame('subtask of', $types[2]->inward);
        $this->assertTrue($types[2]->directed);
        $this->assertTrue($types[2]->aggregation);
        $this->assertSame('relates to', $types[0]->inward, 'an undirected type uses one phrase for both ends');
        $this->assertFalse($types[0]->directed);
    }

    public function test_links_are_flattened_to_one_per_linked_issue_with_the_right_label(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12/links*' => Http::response($this->linksPayload())]);

        $links = YouTrack::issues()->links('DEMO-12');

        $this->assertCount(4, $links, 'entries without linked issues are skipped');

        $this->assertSame('Subtask', $links[0]->type);
        $this->assertSame('parent for', $links[0]->label);
        $this->assertSame('OUTWARD', $links[0]->direction);
        $this->assertSame('DEMO-13', $links[0]->key);
        $this->assertSame('2-60', $links[0]->id);
        $this->assertSame('Child task', $links[0]->summary);
        $this->assertFalse($links[0]->resolved);

        $this->assertSame('subtask of', $links[1]->label);
        $this->assertSame('INWARD', $links[1]->direction);
        $this->assertSame('DEMO-1', $links[1]->key);
        $this->assertTrue($links[1]->resolved);

        $this->assertSame(['relates to', 'relates to'], [$links[2]->label, $links[3]->label]);
        $this->assertSame(['DEMO-7', 'DEMO-8'], [$links[2]->key, $links[3]->key]);
        $this->assertSame('BOTH', $links[2]->direction);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'fields=') && str_contains(urldecode($r->url()), 'linkType('));
    }

    public function test_an_issue_without_links_gives_an_empty_collection(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12/links*' => Http::response([])]);

        $this->assertCount(0, YouTrack::issues()->links('DEMO-12'));
    }

    public function test_link_with_an_outward_phrase_posts_to_the_s_side_with_the_database_id_of_the_target(): void
    {
        Http::fake([
            'pm.test/api/issueLinkTypes*' => Http::response($this->linkTypesPayload()),
            'pm.test/api/issues/DEMO-13*' => Http::response($this->issuePayload(['id' => '2-99', 'idReadable' => 'DEMO-13'])),
            'pm.test/api/issues/DEMO-12/links/*' => Http::response(['id' => '80-3s']),
        ]);

        YouTrack::issues()->link('DEMO-12', 'parent for', 'DEMO-13');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_starts_with($r->url(), 'https://pm.test/api/issues/DEMO-12/links/80-3s/issues')
            && $r->data() === ['id' => '2-99']);
    }

    public function test_link_with_an_inward_phrase_posts_to_the_t_side_and_ignores_case(): void
    {
        Http::fake([
            'pm.test/api/issueLinkTypes*' => Http::response($this->linkTypesPayload()),
            'pm.test/api/issues/DEMO-1*' => Http::response($this->issuePayload(['id' => '2-61', 'idReadable' => 'DEMO-1'])),
            'pm.test/api/issues/DEMO-12/links/*' => Http::response(['id' => '80-3t']),
        ]);

        YouTrack::issues()->link('DEMO-12', 'Subtask OF', 'DEMO-1');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_starts_with($r->url(), 'https://pm.test/api/issues/DEMO-12/links/80-3t/issues')
            && $r->data() === ['id' => '2-61']);
    }

    public function test_link_with_an_undirected_type_uses_the_plain_type_id(): void
    {
        Http::fake([
            'pm.test/api/issueLinkTypes*' => Http::response($this->linkTypesPayload()),
            'pm.test/api/issues/DEMO-12/links/*' => Http::response(['id' => '80-0']),
        ]);

        YouTrack::issues()->link('DEMO-12', 'relates to', '2-70');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_starts_with($r->url(), 'https://pm.test/api/issues/DEMO-12/links/80-0/issues')
            && $r->data() === ['id' => '2-70']);
    }

    public function test_a_database_id_as_target_needs_no_lookup(): void
    {
        Http::fake([
            'pm.test/api/issueLinkTypes*' => Http::response($this->linkTypesPayload()),
            'pm.test/api/issues/*' => Http::response(['id' => '80-1s']),
        ]);

        YouTrack::issues()->link('DEMO-12', 'depends on', '2-70');

        Http::assertSentCount(2);
    }

    public function test_an_unknown_phrase_lists_the_available_ones_and_posts_nothing(): void
    {
        Http::fake(['pm.test/api/issueLinkTypes*' => Http::response($this->linkTypesPayload())]);

        try {
            YouTrack::issues()->link('DEMO-12', 'kapcsolódik', 'DEMO-13');
            $this->fail('Expected YouTrackException.');
        } catch (YouTrackException $e) {
            $this->assertStringContainsString("Unknown link type 'kapcsolódik'", $e->getMessage());
            $this->assertStringContainsString("'relates to'", $e->getMessage());
            $this->assertStringContainsString("'subtask of'", $e->getMessage());
            Http::assertSentCount(1);
        }
    }

    public function test_a_missing_target_issue_throws_not_found_and_posts_nothing(): void
    {
        Http::fake([
            'pm.test/api/issueLinkTypes*' => Http::response($this->linkTypesPayload()),
            'pm.test/api/issues/DEMO-99*' => Http::response(['error' => 'Not Found'], 404),
        ]);

        try {
            YouTrack::issues()->link('DEMO-12', 'relates to', 'DEMO-99');
            $this->fail('Expected NotFoundException.');
        } catch (NotFoundException) {
            Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
        }
    }

    public function test_unlink_deletes_the_link_with_the_target_database_id(): void
    {
        Http::fake([
            'pm.test/api/issueLinkTypes*' => Http::response($this->linkTypesPayload()),
            'pm.test/api/issues/DEMO-13*' => Http::response($this->issuePayload(['id' => '2-99', 'idReadable' => 'DEMO-13'])),
            'pm.test/api/issues/DEMO-12/links/*' => Http::response('', 200),
        ]);

        YouTrack::issues()->unlink('DEMO-12', 'parent for', 'DEMO-13');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && str_starts_with($r->url(), 'https://pm.test/api/issues/DEMO-12/links/80-3s/issues/2-99'));
    }

    public function test_match_finds_undirected_types_by_name_and_returns_null_for_unknown_phrases(): void
    {
        $types = collect($this->linkTypesPayload())->map(fn (array $t): LinkType => LinkType::fromArray($t));

        $this->assertSame('80-0', LinkType::match($types, 'Relates')[0]->id);
        $this->assertNull(LinkType::match($types, 'depend'), 'the name only counts for undirected types');
        $this->assertNull(LinkType::match($types, 'nonsense'));
        $this->assertSame(['relates to'], $types[0]->phrases());
        $this->assertSame(['is required for', 'depends on'], $types[1]->phrases());
    }
}
