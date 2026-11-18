<?php

declare(strict_types=1);

namespace E98\YouTrack\Tests;

use E98\YouTrack\Console\PingCommand;
use E98\YouTrack\Exceptions\ApiException;
use E98\YouTrack\Exceptions\AuthenticationException;
use E98\YouTrack\Exceptions\ConfigurationException;
use E98\YouTrack\Exceptions\ForbiddenException;
use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Exceptions\YouTrackException;
use E98\YouTrack\Facades\YouTrack;
use E98\YouTrack\YouTrackClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class ClientTest extends TestCase
{
    public function test_the_container_resolves_one_shared_client(): void
    {
        $this->assertInstanceOf(YouTrackClient::class, app(YouTrackClient::class));
        $this->assertSame(app(YouTrackClient::class), app('youtrack'));
    }

    public function test_base_url_is_normalized(): void
    {
        config(['youtrack.base_url' => 'https://pm.test/api/']);
        Http::fake(['pm.test/*' => Http::response(['login' => 'anna'])]);

        YouTrack::ping();

        $this->assertSame('https://pm.test', YouTrack::baseUrl());
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://pm.test/api/users/me'));
    }

    public function test_context_path_installations_are_supported(): void
    {
        config(['youtrack.base_url' => 'https://host.test/youtrack']);
        Http::fake(['host.test/*' => Http::response(['login' => 'anna'])]);

        YouTrack::ping();

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://host.test/youtrack/api/users/me'));
    }

    public function test_missing_token_throws_a_configuration_exception(): void
    {
        config(['youtrack.token' => null]);
        Http::fake();

        $this->expectException(ConfigurationException::class);

        try {
            YouTrack::ping();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_missing_base_url_throws_a_configuration_exception(): void
    {
        config(['youtrack.base_url' => '']);

        $this->expectException(ConfigurationException::class);

        YouTrack::ping();
    }

    public function test_http_errors_map_to_typed_exceptions_with_the_api_message(): void
    {
        $cases = [
            400 => ApiException::class,
            401 => AuthenticationException::class,
            403 => ForbiddenException::class,
            404 => NotFoundException::class,
            500 => ApiException::class,
        ];

        // Registered once: with several fakes for the same URL the first one wins.
        $status = 0;
        Http::fake(function () use (&$status) {
            return Http::response(
                ['error' => 'Some error', 'error_description' => "Description {$status}"],
                $status,
            );
        });

        foreach ($cases as $status => $class) {
            try {
                YouTrack::issues()->get('DEMO-1');
                $this->fail("HTTP {$status} did not throw.");
            } catch (ApiException $e) {
                $this->assertInstanceOf($class, $e, "HTTP {$status}");
                $this->assertSame($status, $e->statusCode);
                $this->assertSame("Description {$status}", $e->getMessage());
                $this->assertSame('Some error', $e->body['error']);
            }
        }
    }

    public function test_html_entities_in_error_messages_are_decoded(): void
    {
        Http::fake(['*' => Http::response(
            ['error' => 'Bad Request', 'error_description' => 'St&aacute;tusz sz&uuml;ks&eacute;ges: {In Progress}'],
            400,
        )]);

        try {
            YouTrack::issues()->get('DEMO-1');
            $this->fail('Expected an exception.');
        } catch (ApiException $e) {
            $this->assertSame('Státusz szükséges: {In Progress}', $e->getMessage());
            $this->assertSame('St&aacute;tusz sz&uuml;ks&eacute;ges: {In Progress}', $e->body['error_description']);
        }
    }

    public function test_error_without_a_json_body_gets_a_generic_message(): void
    {
        Http::fake(['*' => Http::response('<html>Bad gateway</html>', 400)]);

        try {
            YouTrack::issues()->get('DEMO-1');
            $this->fail('Expected an exception.');
        } catch (ApiException $e) {
            $this->assertSame('YouTrack request failed with HTTP 400.', $e->getMessage());
            $this->assertNull($e->body);
        }
    }

    public function test_get_requests_are_retried_on_transient_errors(): void
    {
        Http::fake(['pm.test/api/issues/*' => Http::sequence()
            ->push(['error' => 'Service Unavailable'], 503)
            ->push($this->issuePayload(), 200)]);

        $issue = YouTrack::issues()->get('DEMO-12');

        $this->assertSame('DEMO-12', $issue->key);
        Http::assertSentCount(2);
    }

    public function test_get_requests_are_not_retried_on_client_errors(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Not Found'], 404)]);

        try {
            YouTrack::issues()->get('DEMO-1');
        } catch (NotFoundException) {
        }

        Http::assertSentCount(1);
    }

    public function test_writes_are_never_retried(): void
    {
        Http::fake([
            'pm.test/api/admin/projects*' => Http::response($this->projectsPayload()),
            'pm.test/api/issues*' => Http::response(['error' => 'Service Unavailable'], 503),
        ]);

        try {
            YouTrack::issues()->create('Will fail', project: 'DEMO');
            $this->fail('Expected an exception.');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->statusCode);
        }

        $posts = Http::recorded(fn (Request $r): bool => $r->method() === 'POST');
        $this->assertCount(1, $posts);
    }

    public function test_connection_failures_become_package_exceptions(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: could not resolve host'));
        config(['youtrack.retries' => 0]);

        $this->expectException(YouTrackException::class);
        $this->expectExceptionMessage('Could not connect to YouTrack');

        YouTrack::ping();
    }

    public function test_ping_command_reports_success(): void
    {
        Http::fake(['pm.test/api/users/me*' => Http::response(['login' => 'anna', 'fullName' => 'Anna Kiss'])]);

        $this->assertInstanceOf(PingCommand::class, $this->app->make(PingCommand::class));
        $this->artisan('youtrack:ping')->assertSuccessful();
    }

    public function test_ping_command_reports_failure(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        $this->artisan('youtrack:ping')->assertFailed();
    }
}
