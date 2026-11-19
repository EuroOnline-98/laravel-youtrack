<?php

declare(strict_types=1);

namespace E98\YouTrack\Tests;

use E98\YouTrack\Data\Attachment;
use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Exceptions\YouTrackException;
use E98\YouTrack\Facades\YouTrack;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class AttachmentsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'youtrack-attachments-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attachmentPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => '72-5',
            'name' => 'trace.log',
            'size' => 1234,
            'mimeType' => 'text/plain',
            'url' => '/api/files/72-5?sign=abc&updated=1700000000000',
            'created' => 1700000000000,
            'author' => ['id' => '1-1', 'login' => 'anna', 'fullName' => 'Anna Kiss'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function attachment(array $overrides = []): Attachment
    {
        return Attachment::fromArray($this->attachmentPayload($overrides));
    }

    public function test_attachments_lists_the_files_of_an_issue(): void
    {
        Http::fake(['pm.test/api/issues/DEMO-12/attachments*' => Http::response([
            $this->attachmentPayload(),
            $this->attachmentPayload(['id' => '72-6', 'name' => 'shot.png', 'mimeType' => 'image/png', 'size' => 99, 'url' => null]),
        ])]);

        $attachments = YouTrack::issues()->attachments('DEMO-12', limit: 10, skip: 5);

        $this->assertCount(2, $attachments);
        $first = $attachments[0];
        $this->assertSame('72-5', $first->id);
        $this->assertSame('trace.log', $first->name);
        $this->assertSame(1234, $first->size);
        $this->assertSame('text/plain', $first->mimeType);
        $this->assertSame('/api/files/72-5?sign=abc&updated=1700000000000', $first->url);
        $this->assertSame('anna', $first->author?->login);
        $this->assertSame(1700000000, $first->created?->getTimestamp());
        $this->assertNull($attachments[1]->url);

        Http::assertSent(function (Request $r): bool {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $r->method() === 'GET'
                && str_starts_with($r->url(), 'https://pm.test/api/issues/DEMO-12/attachments?')
                && $q['$top'] === '10'
                && $q['$skip'] === '5'
                && str_contains($q['fields'], 'mimeType')
                && $r->hasHeader('Authorization', 'Bearer perm:test-token');
        });
    }

    public function test_attachments_of_an_issue_without_files_is_empty(): void
    {
        Http::fake(['pm.test/api/issues/*' => Http::response([])]);

        $this->assertCount(0, YouTrack::issues()->attachments('DEMO-12'));
    }

    public function test_delete_attachment_accepts_an_id_or_an_attachment(): void
    {
        Http::fake(['pm.test/api/issues/*' => Http::response('', 200)]);

        YouTrack::issues()->deleteAttachment('DEMO-12', '72-5');
        YouTrack::issues()->deleteAttachment('DEMO-12', $this->attachment(['id' => '72-6']));

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && str_starts_with($r->url(), 'https://pm.test/api/issues/DEMO-12/attachments/72-5'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && str_starts_with($r->url(), 'https://pm.test/api/issues/DEMO-12/attachments/72-6'));
    }

    public function test_delete_attachment_maps_missing_attachments_to_not_found(): void
    {
        Http::fake(['pm.test/api/issues/*' => Http::response(['error' => 'Not Found'], 404)]);

        $this->expectException(NotFoundException::class);

        YouTrack::issues()->deleteAttachment('DEMO-12', '72-99');
    }

    public function test_attachment_contents_are_fetched_with_the_token_from_the_relative_url(): void
    {
        Http::fake(['pm.test/api/files/*' => Http::response("binary\x00content")]);

        $contents = YouTrack::issues()->attachmentContents($this->attachment());

        $this->assertSame("binary\x00content", $contents);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
            && $r->url() === 'https://pm.test/api/files/72-5?sign=abc&updated=1700000000000'
            && $r->hasHeader('Authorization', 'Bearer perm:test-token'));
    }

    public function test_an_absolute_url_on_the_same_host_is_allowed(): void
    {
        Http::fake(['pm.test/api/files/*' => Http::response('ok')]);

        $contents = YouTrack::issues()->attachmentContents($this->attachment(['url' => 'https://pm.test/api/files/72-5']));

        $this->assertSame('ok', $contents);
    }

    public function test_the_token_is_never_sent_to_another_host(): void
    {
        Http::fake();

        try {
            YouTrack::issues()->attachmentContents($this->attachment(['url' => 'https://evil.test/steal']));
            $this->fail('Expected YouTrackException.');
        } catch (YouTrackException $e) {
            $this->assertStringContainsString('another host', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_attachment_without_url_cannot_be_downloaded(): void
    {
        Http::fake();

        $this->expectException(YouTrackException::class);
        $this->expectExceptionMessage('has no download URL');

        YouTrack::issues()->attachmentContents($this->attachment(['url' => null]));
    }

    public function test_save_attachment_writes_the_file_and_leaves_no_partial_file(): void
    {
        Http::fake(['pm.test/api/files/*' => Http::response('file body')]);
        $target = $this->directory . DIRECTORY_SEPARATOR . 'trace.log';

        $path = YouTrack::issues()->saveAttachment($this->attachment(), $target);

        $this->assertSame($target, $path);
        $this->assertSame('file body', file_get_contents($target));
        $this->assertFileDoesNotExist($target . '.part');
    }

    public function test_save_attachment_replaces_an_existing_file(): void
    {
        Http::fake(['pm.test/api/files/*' => Http::response('new body')]);
        $target = $this->directory . DIRECTORY_SEPARATOR . 'trace.log';
        file_put_contents($target, 'old body');

        YouTrack::issues()->saveAttachment($this->attachment(), $target);

        $this->assertSame('new body', file_get_contents($target));
    }

    public function test_a_failed_download_leaves_no_file_behind(): void
    {
        Http::fake(['pm.test/api/files/*' => Http::response(['error' => 'Not Found'], 404)]);
        $target = $this->directory . DIRECTORY_SEPARATOR . 'trace.log';

        try {
            YouTrack::issues()->saveAttachment($this->attachment(), $target);
            $this->fail('Expected NotFoundException.');
        } catch (NotFoundException) {
            $this->assertFileDoesNotExist($target);
            $this->assertFileDoesNotExist($target . '.part');
        }
    }

    public function test_save_attachment_needs_an_existing_directory(): void
    {
        Http::fake();

        $this->expectException(YouTrackException::class);
        $this->expectExceptionMessage('directory does not exist');

        YouTrack::issues()->saveAttachment($this->attachment(), $this->directory . DIRECTORY_SEPARATOR . 'missing' . DIRECTORY_SEPARATOR . 'a.log');
    }

    public function test_attachment_stream_can_be_read(): void
    {
        Http::fake(['pm.test/api/files/*' => Http::response('streamed body')]);

        $stream = YouTrack::issues()->attachmentStream($this->attachment());

        $this->assertSame('streamed body', $stream->getContents());
    }

    public function test_attachment_to_array_round_trips_the_main_fields(): void
    {
        $array = $this->attachment()->toArray();

        $this->assertSame('72-5', $array['id']);
        $this->assertSame('trace.log', $array['name']);
        $this->assertSame(1234, $array['size']);
        $this->assertSame('anna', $array['author']['login']);
    }
}
