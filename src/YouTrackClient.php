<?php

declare(strict_types=1);

namespace E98\YouTrack;

use E98\YouTrack\Exceptions\ApiException;
use E98\YouTrack\Exceptions\AuthenticationException;
use E98\YouTrack\Exceptions\ConfigurationException;
use E98\YouTrack\Exceptions\ForbiddenException;
use E98\YouTrack\Exceptions\NotFoundException;
use E98\YouTrack\Exceptions\YouTrackException;
use E98\YouTrack\Resources\Issues;
use E98\YouTrack\Resources\Projects;
use E98\YouTrack\Resources\Tags;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin, authenticated HTTP layer over the YouTrack REST API plus access to the
 * resource classes. All HTTP goes through Laravel's Http facade, so
 * Http::fake() works in your own tests.
 */
class YouTrackClient
{
    private ?Projects $projects = null;

    private ?Issues $issues = null;

    private ?Tags $tags = null;

    /**
     * @param  array<string, mixed>  $config  The "youtrack" config array.
     */
    public function __construct(private readonly array $config)
    {
    }

    public function issues(): Issues
    {
        return $this->issues ??= new Issues($this, $this->projects());
    }

    public function projects(): Projects
    {
        return $this->projects ??= new Projects($this);
    }

    public function tags(): Tags
    {
        return $this->tags ??= new Tags($this);
    }

    /**
     * The user the token belongs to. Handy as a connectivity/credentials check.
     *
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        return $this->get('users/me', ['fields' => Fields::USER . ',email']);
    }

    /**
     * Instance root URL without trailing slash and without "/api".
     */
    public function baseUrl(): string
    {
        $url = rtrim(trim((string) ($this->config['base_url'] ?? '')), '/');

        if ($url === '') {
            throw new ConfigurationException('YouTrack base URL is not configured (YOUTRACK_BASE_URL).');
        }

        return str_ends_with($url, '/api') ? substr($url, 0, -4) : $url;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return mixed Decoded JSON, or null for an empty body.
     */
    public function get(string $uri, array $query = []): mixed
    {
        return $this->request('GET', $uri, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    public function post(string $uri, array $body, array $query = []): mixed
    {
        return $this->request('POST', $uri, $query, $body);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function delete(string $uri, array $query = []): mixed
    {
        return $this->request('DELETE', $uri, $query);
    }

    /**
     * Upload one file as multipart/form-data.
     *
     * @param  array<string, mixed>  $query
     */
    public function upload(string $uri, string $path, ?string $name = null, array $query = []): mixed
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new YouTrackException("Cannot read file for upload: {$path}");
        }

        return $this->send(
            fn (): Response => $this->http()
                ->withQueryParameters($query)
                ->attach('file', $contents, $name ?? basename($path))
                ->post(ltrim($uri, '/')),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    public function request(string $method, string $uri, array $query = [], ?array $body = null): mixed
    {
        $method = strtoupper($method);

        return $this->send(function () use ($method, $uri, $query, $body): Response {
            $http = $this->http()->withQueryParameters($query);

            // Only GETs are retried: a retried POST could create a duplicate issue.
            $retries = (int) $this->option('retries', 0);
            if ($method === 'GET' && $retries > 0) {
                $http = $http->retry(
                    $retries + 1,
                    (int) $this->option('retry_delay', 300),
                    fn (Throwable $e): bool => $this->isTransient($e),
                    throw: false,
                );
            }

            return $http->send($method, ltrim($uri, '/'), $body === null ? [] : ['json' => $body]);
        });
    }

    private function http(): PendingRequest
    {
        $token = trim((string) ($this->config['token'] ?? ''));
        if ($token === '') {
            throw new ConfigurationException('YouTrack token is not configured (YOUTRACK_TOKEN).');
        }

        return Http::baseUrl($this->baseUrl() . '/api')
            ->withToken($token)
            ->acceptJson()
            ->timeout((int) $this->option('timeout', 15))
            ->withOptions(['verify' => (bool) $this->option('verify_ssl', true)]);
    }

    /**
     * Authenticated GET of a file URL on the YouTrack host, e.g. the `url` of an attachment
     * ("/api/files/72-5?sign=..."). The token is only sent to the configured YouTrack host.
     *
     * @param  array<string, mixed>  $options  Guzzle request options, e.g. ['sink' => $path] or ['stream' => true].
     *
     * @throws ApiException When YouTrack answers with an error status.
     */
    public function fetch(string $url, array $options = []): Response
    {
        $absolute = $this->absoluteUrl($url);

        return $this->sendResponse(fn (): Response => $this->http()->withOptions($options)->get($absolute));
    }

    /**
     * @param  callable(): Response  $call
     */
    private function send(callable $call): mixed
    {
        return $this->sendResponse($call)->json();
    }

    /**
     * @param  callable(): Response  $call
     */
    private function sendResponse(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new YouTrackException('Could not connect to YouTrack: ' . $e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw $this->toException($response);
        }

        return $response;
    }

    private function absoluteUrl(string $url): string
    {
        $base = $this->baseUrl();
        $absolute = preg_match('#^https?://#i', $url) === 1 ? $url : $base . '/' . ltrim($url, '/');

        $expected = parse_url($base);
        $actual = parse_url($absolute);

        if (strtolower((string) ($actual['host'] ?? '')) !== strtolower((string) ($expected['host'] ?? ''))
            || ($actual['port'] ?? null) !== ($expected['port'] ?? null)) {
            throw new YouTrackException("Refusing to send the YouTrack token to another host: {$url}");
        }

        return $absolute;
    }

    private function toException(Response $response): ApiException
    {
        $status = $response->status();
        $body = $response->json();
        $body = is_array($body) ? $body : null;

        $message = $body['error_description'] ?? $body['error'] ?? null;
        // YouTrack HTML-escapes localized error text ("St&aacute;tusz"); make it readable.
        $message = is_string($message) && $message !== ''
            ? html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : "YouTrack request failed with HTTP {$status}.";

        return match ($status) {
            401 => new AuthenticationException($message, $status, $body),
            403 => new ForbiddenException($message, $status, $body),
            404 => new NotFoundException($message, $status, $body),
            default => new ApiException($message, $status, $body),
        };
    }

    private function isTransient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && in_array($e->response->status(), [429, 502, 503, 504], true);
    }
}
