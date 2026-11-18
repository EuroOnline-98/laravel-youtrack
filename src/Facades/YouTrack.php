<?php

declare(strict_types=1);

namespace E98\YouTrack\Facades;

use E98\YouTrack\Resources\Issues;
use E98\YouTrack\Resources\Projects;
use E98\YouTrack\Testing\FakeYouTrack;
use E98\YouTrack\YouTrackClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Issues issues()
 * @method static Projects projects()
 * @method static array ping()
 * @method static string baseUrl()
 *
 * @see YouTrackClient
 */
class YouTrack extends Facade
{
    /**
     * Replace the client with an in-memory recording fake for the rest of the test.
     * Nothing is sent over HTTP; use the returned object to seed data and to assert.
     *
     *     $fake = YouTrack::fake();
     *     // ... run the code under test ...
     *     $fake->assertIssueCreated(['summary' => 'Checkout fails']);
     *
     * @param  array<string, mixed>  $config  Overrides for the "youtrack" config, e.g. ['default_project' => 'SUP'].
     */
    public static function fake(array $config = []): FakeYouTrack
    {
        $fake = new FakeYouTrack(array_merge(
            (array) static::getFacadeApplication()->make('config')->get('youtrack', []),
            $config,
        ));

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return YouTrackClient::class;
    }
}
