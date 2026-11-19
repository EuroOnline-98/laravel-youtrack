<?php

declare(strict_types=1);

namespace E98\YouTrack\Tests;

use E98\YouTrack\Data\Tag;
use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Facades\YouTrack;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class TagsWatchersTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function tagPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => '6-4',
            'name' => 'Bug',
            'untagOnResolve' => true,
            'owner' => ['id' => '1-1', 'login' => 'anna', 'fullName' => 'Anna Kiss'],
        ], $overrides);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tagList(): array
    {
        return [
            $this->tagPayload(),
            $this->tagPayload(['id' => '6-5', 'name' => 'Release blocker', 'untagOnResolve' => false]),
        ];
    }

    public function test_tags_all_lists_the_visible_tags(): void
    {
        Http::fake(['pm.test/api/tags*' => Http::response($this->tagList())]);

        $tags = YouTrack::tags()->all(limit: 50);

        $this->assertCount(2, $tags);
        $this->assertSame('6-4', $tags[0]->id);
        $this->assertSame('Bug', $tags[0]->name);
        $this->assertTrue($tags[0]->untagOnResolve);
        $this->assertSame('anna', $tags[0]->owner?->login);
        $this->assertFalse($tags[1]->untagOnResolve);

        Http::assertSent(function (Request $r): bool {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $r->method() === 'GET'
                && str_starts_with($r->url(), 'https://pm.test/api/tags?')
                && $q['$top'] === '50'
                && str_contains($q['fields'], 'untagOnResolve');
        });
    }

    public function test_tags_find_matches_a_name_case_insensitively_or_an_id(): void
    {
        Http::fake(['pm.test/api/tags*' => Http::response($this->tagList())]);

        $this->assertSame('6-5', YouTrack::tags()->find('release BLOCKER')->id);
        $this->assertSame('Bug', YouTrack::tags()->find('6-4')->name);
    }

    public function test_tags_find_throws_not_found_for_an_unknown_tag(): void
    {
        Http::fake(['pm.test/api/tags*' => Http::response($this->tagList())]);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("tag 'Nope' was not found");

        YouTrack::tags()->find('Nope');
    }

    public function test_tags_create_posts_the_name(): void
    {
        Http::fake(['pm.test/api/tags*' => Http::response($this->tagPayload(['id' => '6-9', 'name' => 'Release 0.3']))]);

        $tag = YouTrack::tags()->create('Release 0.3');

        $this->assertSame('6-9', $tag->id);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_starts_with($r->url(), 'https://pm.test/api/tags?')
            && $r->data() === ['name' => 'Release 0.3']);
    }

    public function test_issue_tags_lists_the_tags_of_an_issue(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12/tags*' => Http::response([$this->tagPayload()])]);

        $tags = YouTrack::issues()->tags('DEMO-12');

        $this->assertCount(1, $tags);
        $this->assertSame('Bug', $tags[0]->name);
    }

    public function test_add_tag_by_name_looks_the_tag_up_and_posts_its_id(): void
    {
        Http::fake([
            'pm.test/api/tags*' => Http::response($this->tagList()),
            'pm.test/api/issues/DEMO-12/tags*' => Http::response($this->tagPayload()),
        ]);

        $tag = YouTrack::issues()->addTag('DEMO-12', 'bug');

        $this->assertSame('6-4', $tag->id);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_starts_with($r->url(), 'https://pm.test/api/issues/DEMO-12/tags?')
            && $r->data() === ['id' => '6-4']);
    }

    public function test_add_tag_with_a_tag_object_needs_no_lookup(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12/tags*' => Http::response($this->tagPayload())]);

        YouTrack::issues()->addTag('DEMO-12', Tag::fromArray($this->tagPayload()));

        Http::assertSentCount(1);
    }

    public function test_add_tag_with_an_unknown_name_fails_without_posting(): void
    {
        Http::fake(['pm.test/api/tags*' => Http::response($this->tagList())]);

        try {
            YouTrack::issues()->addTag('DEMO-12', 'Nope');
            $this->fail('Expected NotFoundException.');
        } catch (NotFoundException) {
            Http::assertSentCount(1);
        }
    }

    public function test_add_tag_can_create_the_missing_tag_first(): void
    {
        Http::fake([
            'pm.test/api/tags*' => Http::sequence()
                ->push([])
                ->push($this->tagPayload(['id' => '6-9', 'name' => 'Release 0.3'])),
            'pm.test/api/issues/DEMO-12/tags*' => Http::response($this->tagPayload(['id' => '6-9'])),
        ]);

        $tag = YouTrack::issues()->addTag('DEMO-12', 'Release 0.3', create: true);

        $this->assertSame('6-9', $tag->id);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_starts_with($r->url(), 'https://pm.test/api/tags?')
            && $r->data() === ['name' => 'Release 0.3']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_starts_with($r->url(), 'https://pm.test/api/issues/DEMO-12/tags?')
            && $r->data() === ['id' => '6-9']);
    }

    public function test_an_id_like_name_is_never_created_as_a_tag(): void
    {
        Http::fake(['pm.test/api/tags*' => Http::response([])]);

        try {
            YouTrack::issues()->addTag('DEMO-12', '6-99', create: true);
            $this->fail('Expected NotFoundException.');
        } catch (NotFoundException) {
            Http::assertSentCount(1);
        }
    }

    public function test_remove_tag_by_name_uses_the_tags_of_the_issue(): void
    {
        Http::fake([
            'pm.test/api/issues/DEMO-12/tags/6-4*' => Http::response('', 200),
            'pm.test/api/issues/DEMO-12/tags*' => Http::response([$this->tagPayload()]),
        ]);

        YouTrack::issues()->removeTag('DEMO-12', 'BUG');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && str_starts_with($r->url(), 'https://pm.test/api/issues/DEMO-12/tags/6-4'));
    }

    public function test_remove_tag_by_id_or_tag_object_deletes_directly(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12/tags/*' => Http::response('', 200)]);

        YouTrack::issues()->removeTag('DEMO-12', '6-4');
        YouTrack::issues()->removeTag('DEMO-12', Tag::fromArray($this->tagPayload(['id' => '6-5'])));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/tags/6-4'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/tags/6-5'));
    }

    public function test_remove_tag_that_the_issue_does_not_carry_throws_not_found(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12/tags*' => Http::response([$this->tagPayload()])]);

        try {
            YouTrack::issues()->removeTag('DEMO-12', 'Other');
            $this->fail('Expected NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString("no tag 'Other'", $e->getMessage());
            Http::assertSentCount(1);
        }
    }

    public function test_watchers_are_read_from_the_issue(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12*' => Http::response([
            'watchers' => [
                'hasStar' => false,
                'issueWatchers' => [
                    ['id' => '9-1', 'isStarred' => true, 'user' => ['id' => '1-1', 'login' => 'anna', 'fullName' => 'Anna Kiss']],
                    ['id' => '9-2', 'isStarred' => false, 'user' => ['id' => '1-2', 'login' => 'bela', 'fullName' => 'Béla Nagy']],
                ],
            ],
        ])]);

        $watchers = YouTrack::issues()->watchers('DEMO-12');

        $this->assertCount(2, $watchers);
        $this->assertSame('anna', $watchers[0]->user?->login);
        $this->assertTrue($watchers[0]->starred);
        $this->assertSame('bela', $watchers[1]->user?->login);
        $this->assertFalse($watchers[1]->starred);

        Http::assertSent(function (Request $r): bool {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $r->method() === 'GET' && str_contains($q['fields'], 'issueWatchers');
        });
    }

    public function test_an_issue_without_watchers_gives_an_empty_collection(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12*' => Http::response(['watchers' => ['hasStar' => false, 'issueWatchers' => []]])]);

        $this->assertCount(0, YouTrack::issues()->watchers('DEMO-12'));
    }

    public function test_add_and_remove_watcher_use_the_star_commands(): void
    {
        Http::fake(['pm.test/api/commands*' => Http::response('', 200)]);

        YouTrack::issues()->addWatcher('DEMO-12', 'anna');
        YouTrack::issues()->removeWatcher('DEMO-12', 'anna');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r->data() === ['query' => 'star anna', 'issues' => [['idReadable' => 'DEMO-12']]]);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r->data() === ['query' => 'unstar anna', 'issues' => [['idReadable' => 'DEMO-12']]]);
    }

    public function test_tag_and_watcher_to_array(): void
    {
        $tag = Tag::fromArray($this->tagPayload())->toArray();

        $this->assertSame('6-4', $tag['id']);
        $this->assertSame('anna', $tag['owner']['login']);
    }
}
