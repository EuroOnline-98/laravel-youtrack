<?php

declare(strict_types=1);

namespace E98\YouTrack\Tests;

use E98\YouTrack\Facades\YouTrack;
use E98\YouTrack\YouTrackServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [YouTrackServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['YouTrack' => YouTrack::class];
    }

    protected function defineEnvironment($app): void
    {
        // Don't depend on the Testbench skeleton's cache default (a database store without a table).
        $app['config']->set('cache.default', 'array');

        $app['config']->set('youtrack', [
            'base_url' => 'https://pm.test',
            'token' => 'perm:test-token',
            'default_project' => 'DEMO',
            'state_field' => 'State',
            'timeout' => 5,
            'retries' => 2,
            'retry_delay' => 0,
            'verify_ssl' => true,
            'cache_ttl' => 3600,
        ]);
    }

    /**
     * A realistic issue payload as YouTrack returns it for our `fields` selection.
     *
     * @param  array<string, mixed>  $overrides  Replaces top-level keys.
     * @return array<string, mixed>
     */
    protected function issuePayload(array $overrides = []): array
    {
        return array_merge([
            'id' => '2-45',
            'idReadable' => 'DEMO-12',
            'summary' => 'Printer on fire',
            'description' => 'Smoke everywhere',
            'created' => 1700000000000,
            'updated' => 1700000100000,
            'resolved' => null,
            'project' => ['id' => '0-1', 'shortName' => 'DEMO', 'name' => 'Demo'],
            'reporter' => ['id' => '1-1', 'login' => 'anna', 'fullName' => 'Anna Kiss'],
            'customFields' => [
                [
                    '$type' => 'StateIssueCustomField',
                    'name' => 'State',
                    'value' => ['$type' => 'StateBundleElement', 'name' => 'Open'],
                ],
                [
                    '$type' => 'SingleUserIssueCustomField',
                    'name' => 'Assignee',
                    'value' => ['$type' => 'User', 'login' => 'bela', 'fullName' => 'Béla Nagy'],
                ],
                [
                    '$type' => 'SingleEnumIssueCustomField',
                    'name' => 'Priority',
                    'value' => ['$type' => 'EnumBundleElement', 'name' => 'Major'],
                ],
            ],
        ], $overrides);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function projectsPayload(): array
    {
        return [
            ['id' => '0-1', 'shortName' => 'DEMO', 'name' => 'Demo', 'archived' => false],
            ['id' => '0-2', 'shortName' => 'SUP', 'name' => 'Support', 'archived' => false],
        ];
    }
}
