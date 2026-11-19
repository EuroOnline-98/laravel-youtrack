<?php

declare(strict_types=1);

namespace E98\YouTrack\Tests;

use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Exceptions\YouTrackException;
use E98\YouTrack\Facades\YouTrack;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\ExpectationFailedException;

final class FakeLinksTest extends TestCase
{
    public function test_link_types_are_the_youtrack_defaults(): void
    {
        YouTrack::fake();

        $types = YouTrack::issues()->linkTypes();

        $this->assertSame(['Relates', 'Depend', 'Duplicate', 'Subtask'], $types->pluck('name')->all());
    }

    public function test_a_directed_link_reads_differently_from_each_end(): void
    {
        $fake = YouTrack::fake()
            ->withIssue('DEMO-1', ['summary' => 'Epic'])
            ->withIssue('DEMO-2', ['summary' => 'Child']);

        YouTrack::issues()->link('DEMO-2', 'subtask of', 'DEMO-1');

        $fromChild = YouTrack::issues()->links('DEMO-2');
        $fromParent = YouTrack::issues()->links('DEMO-1');

        $this->assertCount(1, $fromChild);
        $this->assertSame('subtask of', $fromChild[0]->label);
        $this->assertSame('INWARD', $fromChild[0]->direction);
        $this->assertSame('Subtask', $fromChild[0]->type);
        $this->assertSame('DEMO-1', $fromChild[0]->key);
        $this->assertSame('Epic', $fromChild[0]->summary);

        $this->assertCount(1, $fromParent);
        $this->assertSame('parent for', $fromParent[0]->label);
        $this->assertSame('OUTWARD', $fromParent[0]->direction);
        $this->assertSame('DEMO-2', $fromParent[0]->key);

        $fake->assertLinked('DEMO-2');
        $fake->assertLinked('demo-2', 'Subtask Of', 'demo-1');
    }

    public function test_the_outward_phrase_gives_the_mirror_image(): void
    {
        YouTrack::fake()->withIssue('DEMO-1')->withIssue('DEMO-2');

        YouTrack::issues()->link('DEMO-1', 'parent for', 'DEMO-2');

        $this->assertSame('subtask of', YouTrack::issues()->links('DEMO-2')[0]->label);
    }

    public function test_an_undirected_link_is_the_same_from_both_ends_and_not_duplicated(): void
    {
        YouTrack::fake()->withIssue('DEMO-1')->withIssue('DEMO-2');

        YouTrack::issues()->link('DEMO-1', 'relates to', 'DEMO-2');
        YouTrack::issues()->link('DEMO-2', 'relates to', 'DEMO-1');

        $one = YouTrack::issues()->links('DEMO-1');
        $two = YouTrack::issues()->links('DEMO-2');

        $this->assertCount(1, $one);
        $this->assertCount(1, $two);
        $this->assertSame(['relates to', 'BOTH', 'DEMO-2'], [$one[0]->label, $one[0]->direction, $one[0]->key]);
        $this->assertSame(['relates to', 'BOTH', 'DEMO-1'], [$two[0]->label, $two[0]->direction, $two[0]->key]);
    }

    public function test_linking_twice_keeps_one_link(): void
    {
        YouTrack::fake()->withIssue('DEMO-1')->withIssue('DEMO-2');

        YouTrack::issues()->link('DEMO-2', 'depends on', 'DEMO-1');
        YouTrack::issues()->link('DEMO-2', 'depends on', 'DEMO-1');

        $this->assertCount(1, YouTrack::issues()->links('DEMO-2'));
    }

    public function test_the_resolved_flag_of_the_linked_issue_is_reported(): void
    {
        YouTrack::fake()->withIssue('DEMO-1', ['state' => 'Fixed'])->withIssue('DEMO-2');

        YouTrack::issues()->link('DEMO-2', 'subtask of', 'DEMO-1');

        $this->assertTrue(YouTrack::issues()->links('DEMO-2')[0]->resolved);
    }

    public function test_unlink_removes_the_link_from_both_ends(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-1')->withIssue('DEMO-2')->withLink('DEMO-2', 'subtask of', 'DEMO-1');

        YouTrack::issues()->unlink('DEMO-2', 'subtask of', 'DEMO-1');

        $this->assertCount(0, YouTrack::issues()->links('DEMO-2'));
        $this->assertCount(0, YouTrack::issues()->links('DEMO-1'));
        $fake->assertUnlinked('DEMO-2', 'subtask of', 'DEMO-1');
    }

    public function test_unlinking_something_that_is_not_linked_throws_not_found(): void
    {
        YouTrack::fake()->withIssue('DEMO-1')->withIssue('DEMO-2');

        $this->expectException(NotFoundException::class);

        YouTrack::issues()->unlink('DEMO-2', 'subtask of', 'DEMO-1');
    }

    public function test_an_unknown_phrase_throws_with_the_available_ones(): void
    {
        YouTrack::fake()->withIssue('DEMO-1')->withIssue('DEMO-2');

        try {
            YouTrack::issues()->link('DEMO-1', 'kapcsolódik', 'DEMO-2');
            $this->fail('Expected YouTrackException.');
        } catch (YouTrackException $e) {
            $this->assertStringContainsString("'parent for'", $e->getMessage());
        }
    }

    public function test_a_target_that_does_not_exist_throws_not_found_with_a_hint(): void
    {
        YouTrack::fake()->withIssue('DEMO-1');

        try {
            YouTrack::issues()->link('DEMO-1', 'relates to', 'DEMO-9');
            $this->fail('Expected NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString("withIssue('DEMO-9')", $e->getMessage());
        }
    }

    public function test_seeded_links_are_not_recorded_as_calls(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-1')->withIssue('DEMO-2')->withLink('DEMO-2', 'subtask of', 'DEMO-1');

        $fake->assertNothingSent();
    }

    public function test_assert_linked_fails_for_another_target_or_phrase(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-1')->withIssue('DEMO-2')->withIssue('DEMO-3');
        YouTrack::issues()->link('DEMO-2', 'subtask of', 'DEMO-1');

        $this->expectException(ExpectationFailedException::class);

        $fake->assertLinked('DEMO-2', 'subtask of', 'DEMO-3');
    }

    public function test_assert_unlinked_fails_when_nothing_was_removed(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-1')->withIssue('DEMO-2')->withLink('DEMO-2', 'relates to', 'DEMO-1');

        $this->expectException(ExpectationFailedException::class);

        $fake->assertUnlinked('DEMO-2');
    }

    public function test_deleting_an_issue_drops_its_links(): void
    {
        YouTrack::fake()->withIssue('DEMO-1')->withIssue('DEMO-2')->withLink('DEMO-2', 'subtask of', 'DEMO-1');

        YouTrack::issues()->delete('DEMO-1');

        $this->assertCount(0, YouTrack::issues()->links('DEMO-2'));
    }

    public function test_nothing_goes_over_http(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        YouTrack::fake()->withIssue('DEMO-1')->withIssue('DEMO-2');
        YouTrack::issues()->link('DEMO-2', 'subtask of', 'DEMO-1');
        YouTrack::issues()->links('DEMO-2');
        YouTrack::issues()->unlink('DEMO-2', 'subtask of', 'DEMO-1');

        Http::assertNothingSent();
    }
}
