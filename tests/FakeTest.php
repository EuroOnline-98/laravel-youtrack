<?php

declare(strict_types=1);

namespace E98\YouTrack\Tests;

use E98\YouTrack\CustomField;
use E98\YouTrack\Data\Issue;
use E98\YouTrack\Exceptions\ConfigurationException;
use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Exceptions\YouTrackException;
use E98\YouTrack\Facades\YouTrack;
use E98\YouTrack\Resources\Issues;
use E98\YouTrack\Testing\FakeYouTrack;
use E98\YouTrack\YouTrackClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\ExpectationFailedException;

final class FakeTest extends TestCase
{
    public function test_fake_replaces_the_client_in_the_facade_and_the_container(): void
    {
        $fake = YouTrack::fake();

        $this->assertInstanceOf(FakeYouTrack::class, $fake);
        $this->assertSame($fake, $this->app->make(YouTrackClient::class));
        $this->assertSame($fake, $this->app->make('youtrack'));
        $this->assertInstanceOf(Issues::class, YouTrack::issues());
    }

    public function test_nothing_goes_over_http(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        YouTrack::fake();
        $issue = YouTrack::issues()->create('Checkout fails');
        YouTrack::issues()->comment($issue->key, 'Looking into it');
        YouTrack::issues()->changeState($issue->key, 'In Progress');
        YouTrack::issues()->search('checkout');

        Http::assertNothingSent();
    }

    public function test_it_works_without_a_configured_base_url_or_token(): void
    {
        config(['youtrack.base_url' => null, 'youtrack.token' => null]);

        YouTrack::fake();
        $issue = YouTrack::issues()->create('No config needed');

        $this->assertSame('DEMO-1', $issue->key);
        $this->assertSame('https://youtrack.fake/issue/DEMO-1', $issue->url);
    }

    public function test_create_returns_an_issue_with_numbered_keys_and_the_default_state(): void
    {
        YouTrack::fake();

        $first = YouTrack::issues()->create('Checkout fails', 'Stack trace');
        $second = YouTrack::issues()->create('Another one', project: 'SUP');
        $third = YouTrack::issues()->create('Third');

        $this->assertInstanceOf(Issue::class, $first);
        $this->assertSame('DEMO-1', $first->key);
        $this->assertSame('Checkout fails', $first->summary);
        $this->assertSame('Stack trace', $first->description);
        $this->assertSame('DEMO', $first->project);
        $this->assertSame('Submitted', $first->state);
        $this->assertFalse($first->isResolved());
        $this->assertSame('fake', $first->reporter?->login);
        $this->assertNotNull($first->created);
        $this->assertSame('SUP-1', $second->key);
        $this->assertSame('DEMO-2', $third->key);
    }

    public function test_create_applies_custom_fields_including_the_state(): void
    {
        YouTrack::fake();

        $issue = YouTrack::issues()->create('Priority one', customFields: [
            CustomField::enum('Priority', 'Critical'),
            CustomField::state('State', 'Open'),
        ]);

        $this->assertSame('Open', $issue->state);
        $this->assertSame('Critical', $issue->field('Priority'));
    }

    public function test_the_initial_state_can_be_changed(): void
    {
        YouTrack::fake()->initialStateIs('Open');

        $this->assertSame('Open', YouTrack::issues()->create('x')->state);
    }

    public function test_create_without_any_project_fails_like_the_real_client(): void
    {
        config(['youtrack.default_project' => null]);
        YouTrack::fake();

        $this->expectException(ConfigurationException::class);

        YouTrack::issues()->create('No project');
    }

    public function test_assert_issue_created_with_an_array(): void
    {
        $fake = YouTrack::fake();

        YouTrack::issues()->create(
            'Checkout fails',
            'Stack trace',
            'SUP',
            [CustomField::enum('Priority', 'Critical')],
        );

        $fake->assertIssueCreated();
        $fake->assertIssueCreated(['summary' => 'Checkout fails']);
        $fake->assertIssueCreated(['project' => 'sup', 'description' => 'Stack trace']);
        $fake->assertIssueCreated(['fields' => ['Priority' => 'Critical']]);
        $fake->assertIssueCreatedTimes(1);
    }

    public function test_assert_issue_created_with_a_closure(): void
    {
        $fake = YouTrack::fake();

        YouTrack::issues()->create('Checkout fails');

        $fake->assertIssueCreated(fn (array $call): bool => str_starts_with($call['summary'], 'Checkout'));
    }

    public function test_assert_issue_created_fails_when_nothing_matches(): void
    {
        $fake = YouTrack::fake();
        YouTrack::issues()->create('Checkout fails');

        $this->expectException(ExpectationFailedException::class);

        $fake->assertIssueCreated(['summary' => 'Something else']);
    }

    public function test_assert_issue_created_fails_when_a_field_differs(): void
    {
        $fake = YouTrack::fake();
        YouTrack::issues()->create('Checkout fails', customFields: [CustomField::enum('Priority', 'Minor')]);

        $this->expectException(ExpectationFailedException::class);

        $fake->assertIssueCreated(['fields' => ['Priority' => 'Critical']]);
    }

    public function test_assert_no_issue_created(): void
    {
        $fake = YouTrack::fake();
        $fake->assertNoIssueCreated();

        YouTrack::issues()->create('Now there is one');

        $this->expectException(ExpectationFailedException::class);

        $fake->assertNoIssueCreated();
    }

    public function test_seeded_issues_can_be_read_and_keep_the_numbering_going(): void
    {
        $fake = YouTrack::fake()->withIssue('SUP-12', ['summary' => 'Old problem', 'state' => 'Fixed']);

        $issue = YouTrack::issues()->get('sup-12');

        $this->assertSame('SUP-12', $issue->key);
        $this->assertSame('Old problem', $issue->summary);
        $this->assertSame('Fixed', YouTrack::issues()->state('SUP-12'));
        $this->assertTrue($issue->isResolved());
        $this->assertSame('SUP-13', YouTrack::issues()->create('Next', project: 'SUP')->key);
        $fake->assertIssueCreatedTimes(1);
    }

    public function test_get_finds_an_issue_by_its_database_id(): void
    {
        YouTrack::fake();
        $created = YouTrack::issues()->create('By id');

        $this->assertSame('DEMO-1', YouTrack::issues()->get($created->id)->key);
    }

    public function test_an_unknown_issue_throws_not_found_with_a_hint(): void
    {
        YouTrack::fake();

        try {
            YouTrack::issues()->get('SUP-99');
            $this->fail('Expected NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->statusCode);
            $this->assertStringContainsString("withIssue('SUP-99')", $e->getMessage());
        }
    }

    public function test_a_seed_with_a_bad_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        YouTrack::fake()->withIssue('nonsense');
    }

    public function test_comments_are_stored_and_asserted(): void
    {
        $fake = YouTrack::fake()
            ->withIssue('DEMO-5')
            ->withComment('DEMO-5', 'Earlier comment', 'anna');

        $comment = YouTrack::issues()->comment('DEMO-5', 'Deployed the fix');
        $comments = YouTrack::issues()->comments('DEMO-5');

        $this->assertSame('Deployed the fix', $comment->text);
        $this->assertSame('fake', $comment->author?->login);
        $this->assertCount(2, $comments);
        $this->assertSame('anna', $comments[0]->author?->login);
        $this->assertSame('Deployed the fix', $comments[1]->text);

        $fake->assertCommented('DEMO-5');
        $fake->assertCommented('demo-5', 'Deployed the fix');
        $fake->assertCommented('DEMO-5', fn (string $text): bool => str_contains($text, 'fix'));
    }

    public function test_assert_commented_fails_for_other_text_and_other_issues(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5')->withIssue('DEMO-6');
        YouTrack::issues()->comment('DEMO-5', 'Hello');

        $this->expectException(ExpectationFailedException::class);

        $fake->assertCommented('DEMO-6');
    }

    public function test_assert_nothing_commented(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5');
        $fake->assertNothingCommented();

        YouTrack::issues()->comment('DEMO-5', 'Hello');

        $this->expectException(ExpectationFailedException::class);

        $fake->assertNothingCommented();
    }

    public function test_change_state_updates_the_issue_and_is_asserted(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5', ['state' => 'Open']);

        $moved = YouTrack::issues()->changeState('DEMO-5', 'In Progress');

        $this->assertSame('In Progress', $moved->state);
        $this->assertSame('In Progress', YouTrack::issues()->state('DEMO-5'));
        $this->assertFalse($moved->isResolved());
        $fake->assertStateChanged('DEMO-5');
        $fake->assertStateChanged('DEMO-5', 'In Progress');
    }

    public function test_resolving_and_reopening_follow_the_resolved_states(): void
    {
        YouTrack::fake()->withIssue('DEMO-5', ['state' => 'Open']);

        $fixed = YouTrack::issues()->changeState('DEMO-5', 'Fixed');
        $this->assertTrue($fixed->isResolved());
        $this->assertNotNull($fixed->resolvedAt);

        $reopened = YouTrack::issues()->changeState('DEMO-5', 'Open');
        $this->assertFalse($reopened->isResolved());
    }

    public function test_the_resolved_states_can_be_changed(): void
    {
        YouTrack::fake()->resolvedStatesAre(['Kész'])->withIssue('DEMO-5');

        $this->assertTrue(YouTrack::issues()->changeState('DEMO-5', 'Kész')->isResolved());
        $this->assertFalse(YouTrack::issues()->changeState('DEMO-5', 'Fixed')->isResolved());
    }

    public function test_assert_state_changed_fails_for_another_state(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5');
        YouTrack::issues()->changeState('DEMO-5', 'Open');

        $this->expectException(ExpectationFailedException::class);

        $fake->assertStateChanged('DEMO-5', 'Fixed');
    }

    public function test_update_changes_the_stored_issue(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5', ['summary' => 'Old']);

        YouTrack::issues()->update('DEMO-5', [
            'summary' => 'New',
            'description' => 'Details',
            'customFields' => [CustomField::enum('Priority', 'Major')],
        ]);

        $issue = YouTrack::issues()->get('DEMO-5');

        $this->assertSame('New', $issue->summary);
        $this->assertSame('Details', $issue->description);
        $this->assertSame('Major', $issue->field('Priority'));
        $fake->assertUpdated('DEMO-5');
        $fake->assertUpdated('DEMO-5', fn (array $attributes): bool => $attributes['summary'] === 'New');
    }

    public function test_command_attach_and_delete_are_recorded(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5');

        YouTrack::issues()->command('DEMO-5', 'for anna State Fixed');
        $attachment = YouTrack::issues()->attach('DEMO-5', '/does/not/exist.log', 'trace.log');
        YouTrack::issues()->delete('DEMO-5');

        $this->assertSame('trace.log', $attachment[0]['name']);
        $fake->assertCommandSent('DEMO-5');
        $fake->assertCommandSent('DEMO-5', 'for anna State Fixed');
        $fake->assertAttached('DEMO-5', 'trace.log');
        $fake->assertDeleted('DEMO-5');

        $this->expectException(NotFoundException::class);
        YouTrack::issues()->get('DEMO-5');
    }

    public function test_search_matches_text_by_default(): void
    {
        YouTrack::fake()
            ->withIssue('DEMO-1', ['summary' => 'Printer on fire'])
            ->withIssue('DEMO-2', ['summary' => 'Login broken', 'description' => 'the PRINTER driver']);

        $this->assertCount(2, YouTrack::issues()->search());
        $this->assertSame(['DEMO-1', 'DEMO-2'], YouTrack::issues()->search('printer')->pluck('key')->all());
        $this->assertSame(['DEMO-2'], YouTrack::issues()->search('login')->pluck('key')->all());
        $this->assertSame(['DEMO-2'], YouTrack::issues()->search('', limit: 1, skip: 1)->pluck('key')->all());
        $this->assertCount(0, YouTrack::issues()->search('nothing like this'));
    }

    public function test_search_can_be_replaced_for_query_language_queries(): void
    {
        $fake = YouTrack::fake()
            ->withIssue('DEMO-1')
            ->searchUsing(fn (string $query) => $query === 'project: DEMO #Unresolved'
                ? [YouTrack::issues()->get('DEMO-1')]
                : []);

        $this->assertCount(1, YouTrack::issues()->search('project: DEMO #Unresolved'));
        $this->assertCount(0, YouTrack::issues()->search('something else'));
        $this->assertCount(2, $fake->recorded('search'));
    }

    public function test_projects_are_faked_too(): void
    {
        YouTrack::fake()->withProject('SUP', 'Support');

        $projects = YouTrack::projects();

        $this->assertSame(['DEMO', 'SUP'], $projects->all()->pluck('shortName')->all());
        $this->assertSame('Support', $projects->find('sup')->name);
        $this->assertSame($projects->find('SUP')->id, $projects->resolveId('SUP'));
        $this->assertSame('0-9', $projects->resolveId('0-9'));

        $this->expectException(NotFoundException::class);
        $projects->find('NOPE');
    }

    public function test_a_project_can_be_given_by_its_database_id(): void
    {
        YouTrack::fake()->withProject('SUP');
        $id = YouTrack::projects()->find('SUP')->id;

        $this->assertSame('SUP-1', YouTrack::issues()->create('By project id', project: $id)->key);
    }

    public function test_assert_nothing_sent_counts_reads_too(): void
    {
        $fake = YouTrack::fake()->withIssue('DEMO-5');
        $fake->assertNothingSent();

        YouTrack::issues()->get('DEMO-5');

        $this->expectException(ExpectationFailedException::class);

        $fake->assertNothingSent();
    }

    public function test_raw_http_calls_are_refused(): void
    {
        YouTrack::fake();

        $this->expectException(YouTrackException::class);
        $this->expectExceptionMessage('Unexpected request while YouTrack is faked: GET users/me');

        YouTrack::request('GET', 'users/me');
    }

    public function test_ping_does_not_need_a_server(): void
    {
        YouTrack::fake();

        $this->assertSame('fake', YouTrack::ping()['login']);
    }

    public function test_fake_config_overrides_apply(): void
    {
        YouTrack::fake(['default_project' => 'OPS', 'state_field' => 'Status']);

        $issue = YouTrack::issues()->create('Custom state field');

        $this->assertSame('OPS-1', $issue->key);
        $this->assertSame('Submitted', $issue->state);
        $this->assertSame('Submitted', $issue->field('Status'));
    }

    public function test_the_code_under_test_can_type_hint_the_client(): void
    {
        YouTrack::fake();

        $service = new class ($this->app->make(YouTrackClient::class)) {
            public function __construct(private readonly YouTrackClient $youTrack)
            {
            }

            public function report(string $summary): string
            {
                return $this->youTrack->issues()->create($summary)->key;
            }
        };

        $this->assertSame('DEMO-1', $service->report('Injected client'));
    }
}
