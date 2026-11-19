<?php

declare(strict_types=1);

namespace E98\YouTrack\Tests;

use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Facades\YouTrack;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\ExpectationFailedException;

final class FakeTagsWatchersTest extends TestCase
{
    public function test_a_registered_tag_can_be_added_and_listed(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5')->withTag('Bug');

        $tag = YouTrack::issues()->addTag('DEMO-5', 'bug');
        $tags = YouTrack::issues()->tags('DEMO-5');

        $this->assertSame('Bug', $tag->name);
        $this->assertCount(1, $tags);
        $this->assertSame($tag->id, $tags[0]->id);
        $this->assertSame('fake', $tags[0]->owner?->login);
        $fake->assertTagged('DEMO-5');
        $fake->assertTagged('demo-5', 'BUG');
        $fake->assertTagged('DEMO-5', $tag->id);
    }

    public function test_adding_the_same_tag_twice_keeps_one_tag(): void
    {
        YouTrack::fake()->withIssue('DEMO-5')->withTag('Bug');

        YouTrack::issues()->addTag('DEMO-5', 'Bug');
        YouTrack::issues()->addTag('DEMO-5', 'Bug');

        $this->assertCount(1, YouTrack::issues()->tags('DEMO-5'));
    }

    public function test_an_unknown_tag_throws_not_found_with_a_hint(): void
    {
        YouTrack::fake()->withIssue('DEMO-5');

        try {
            YouTrack::issues()->addTag('DEMO-5', 'Nope');
            $this->fail('Expected NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString("withTag('Nope')", $e->getMessage());
        }
    }

    public function test_a_missing_tag_can_be_created_on_the_fly(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5');

        $tag = YouTrack::issues()->addTag('DEMO-5', 'Release 0.3', create: true);

        $this->assertSame('Release 0.3', $tag->name);
        $this->assertSame(['Release 0.3'], YouTrack::tags()->all()->pluck('name')->all());
        $fake->assertTagCreated();
        $fake->assertTagCreated('release 0.3');
        $fake->assertTagged('DEMO-5', 'Release 0.3');
    }

    public function test_assert_tag_created_fails_when_the_tag_only_existed(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5')->withTag('Bug');
        YouTrack::issues()->addTag('DEMO-5', 'Bug', create: true);

        $this->expectException(ExpectationFailedException::class);

        $fake->assertTagCreated();
    }

    public function test_tags_can_be_created_and_listed_through_the_tags_resource(): void
    {
        $fake = YouTrack::fake();

        $created = YouTrack::tags()->create('Urgent');

        $this->assertSame('Urgent', YouTrack::tags()->find('urgent')->name);
        $this->assertSame($created->id, YouTrack::tags()->find($created->id)->id);
        $fake->assertTagCreated('Urgent');
    }

    public function test_removing_a_tag(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5')->withTagOn('DEMO-5', 'Bug')->withTagOn('DEMO-5', 'Blocker');

        YouTrack::issues()->removeTag('DEMO-5', 'bug');

        $this->assertSame(['Blocker'], YouTrack::issues()->tags('DEMO-5')->pluck('name')->all());
        $fake->assertUntagged('DEMO-5', 'Bug');
        $fake->assertUntagged('DEMO-5');
    }

    public function test_removing_a_tag_the_issue_does_not_carry_throws_not_found(): void
    {
        YouTrack::fake()->withIssue('DEMO-5')->withTag('Bug');

        $this->expectException(NotFoundException::class);

        YouTrack::issues()->removeTag('DEMO-5', 'Bug');
    }

    public function test_assert_tagged_fails_for_another_tag(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5')->withTag('Bug')->withTag('Blocker');
        YouTrack::issues()->addTag('DEMO-5', 'Bug');

        $this->expectException(ExpectationFailedException::class);

        $fake->assertTagged('DEMO-5', 'Blocker');
    }

    public function test_seeded_tags_and_watchers_are_not_recorded_as_calls(): void
    {
        $fake = YouTrack::fake()
            ->withIssue('DEMO-5')
            ->withTag('Bug')
            ->withTagOn('DEMO-5', 'Blocker')
            ->withWatcher('DEMO-5', 'anna');

        $fake->assertNothingSent();
    }

    public function test_watchers_are_listed_with_their_star_flag(): void
    {
        YouTrack::fake()
            ->withIssue('DEMO-5')
            ->withWatcher('DEMO-5', 'anna')
            ->withWatcher('DEMO-5', 'bela', starred: false);

        $watchers = YouTrack::issues()->watchers('DEMO-5');

        $this->assertSame(['anna', 'bela'], $watchers->map(fn ($w) => $w->user?->login)->all());
        $this->assertSame([true, false], $watchers->map(fn ($w) => $w->starred)->all());
    }

    public function test_add_watcher_is_idempotent_and_asserted(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5');

        YouTrack::issues()->addWatcher('DEMO-5', 'anna');
        YouTrack::issues()->addWatcher('DEMO-5', 'anna');

        $this->assertCount(1, YouTrack::issues()->watchers('DEMO-5'));
        $fake->assertWatcherAdded('DEMO-5');
        $fake->assertWatcherAdded('demo-5', 'anna');
    }

    public function test_remove_watcher_only_removes_users_who_starred_the_issue(): void
    {
        $fake = YouTrack::fake()
            ->withIssue('DEMO-5')
            ->withWatcher('DEMO-5', 'anna')
            ->withWatcher('DEMO-5', 'bela', starred: false);

        YouTrack::issues()->removeWatcher('DEMO-5', 'anna');
        YouTrack::issues()->removeWatcher('DEMO-5', 'bela');

        $this->assertSame(['bela'], YouTrack::issues()->watchers('DEMO-5')->map(fn ($w) => $w->user?->login)->all());
        $fake->assertWatcherRemoved('DEMO-5', 'anna');
        $fake->assertWatcherRemoved('DEMO-5');
    }

    public function test_assert_watcher_added_fails_when_none_was_added(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5')->withWatcher('DEMO-5', 'anna');

        $this->expectException(ExpectationFailedException::class);

        $fake->assertWatcherAdded('DEMO-5', 'anna');
    }

    public function test_deleting_an_issue_drops_its_tags_and_watchers(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5')->withTagOn('DEMO-5', 'Bug')->withWatcher('DEMO-5', 'anna');

        YouTrack::issues()->delete('DEMO-5');
        $fake->withIssue('DEMO-5');

        $this->assertCount(0, YouTrack::issues()->tags('DEMO-5'));
        $this->assertCount(0, YouTrack::issues()->watchers('DEMO-5'));
    }

    public function test_nothing_goes_over_http(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        YouTrack::fake()->withIssue('DEMO-5')->withTag('Bug');
        YouTrack::issues()->addTag('DEMO-5', 'Bug');
        YouTrack::issues()->addWatcher('DEMO-5', 'anna');
        YouTrack::issues()->removeTag('DEMO-5', 'Bug');

        Http::assertNothingSent();
    }
}
