# e98/laravel-youtrack

Laravel wrapper for the [JetBrains YouTrack](https://www.jetbrains.com/youtrack/) REST API.
Create issues, read their status and details, comment, change state, search and attach files from inside a Laravel application.

- PHP ^8.2, Laravel 11, 12 and 13
- Built on Laravel's `Http` client, so `Http::fake()` works in your own tests
- Works with YouTrack Cloud and self-hosted instances (permanent token auth)

## Installation

```bash
composer require e98/laravel-youtrack
```

Laravel auto-discovers the service provider and the `YouTrack` facade.

### Local development against a project

Point a project at your working copy instead of the repository (Composer symlinks it):

```json
"repositories": [
    { "type": "path", "url": "../laravel-youtrack" }
]
```

## Configuration

```dotenv
YOUTRACK_BASE_URL=https://youtrack.example.com
YOUTRACK_TOKEN=perm:xxxxxxxx
YOUTRACK_PROJECT=SUP
```

Create the token in YouTrack under *Profile > Account Security > New token*. The token's user needs the *Create Issue* and *Read Issue* permissions in the target project (plus *Update Issue* / *Add Comment* for those calls).

Optional settings (publish the file with `php artisan vendor:publish --tag=youtrack-config`):

| Variable | Default | Meaning |
|---|---|---|
| `YOUTRACK_PROJECT` | none | Default project (short name or id) for `create()` |
| `YOUTRACK_STATE_FIELD` | `State` | Name of the status field |
| `YOUTRACK_TIMEOUT` | `15` | Seconds |
| `YOUTRACK_RETRIES` / `YOUTRACK_RETRY_DELAY` | `2` / `300` | GET retries on connection errors and 429/502/503/504. Writes are never retried |
| `YOUTRACK_VERIFY_SSL` | `true` | Disable only for internal test setups |
| `YOUTRACK_CACHE_TTL` | `3600` | Cache for project short name to id lookups (0 disables) |

Check the setup:

```bash
php artisan youtrack:ping
```

## Usage

```php
use E98\YouTrack\CustomField;
use E98\YouTrack\Facades\YouTrack;

// Create
$issue = YouTrack::issues()->create(
    summary: 'Checkout fails with 500',
    description: "Steps:\n1. ...",
    project: 'SUP',                       // optional, falls back to YOUTRACK_PROJECT
    customFields: [
        CustomField::enum('Priority', 'Critical'),
        CustomField::enum('Type', 'Bug'),
        CustomField::user('Assignee', 'anna'),
    ],
);

$issue->key;    // "SUP-123"
$issue->url;    // "https://youtrack.example.com/issue/SUP-123"

// Read
$issue = YouTrack::issues()->get('SUP-123');
$issue->state;          // "Open"
$issue->isResolved();   // false
$issue->assignee();     // "anna"
$issue->field('Priority');
$issue->reporter?->login;
$issue->created;        // CarbonImmutable

YouTrack::issues()->state('SUP-123');   // only the status

// Search (YouTrack query language)
$open = YouTrack::issues()->search('project: SUP #Unresolved', limit: 50);

// Change
YouTrack::issues()->changeState('SUP-123', 'In Progress');
YouTrack::issues()->update('SUP-123', ['summary' => 'New title']);
YouTrack::issues()->command('SUP-123', 'for anna State Fixed', comment: 'Done');

// Comments and files
YouTrack::issues()->comment('SUP-123', 'Reproduced on staging.');
YouTrack::issues()->comments('SUP-123');
YouTrack::issues()->attach('SUP-123', storage_path('logs/laravel.log'));

// Projects
YouTrack::projects()->all();
YouTrack::projects()->find('SUP');

YouTrack::issues()->delete('SUP-123');
```

Notes on `command()`:

- Prefer `changeState()` for plain state changes; it sends a typed field value and does not depend on the command parser.
- Multi-word values are written without braces (`'State In Progress'`). On a YouTrack with a Hungarian UI the brace form (`'State {In Progress}'`) was rejected with HTTP 400.
- The command is parsed in the language of the token's user, so error messages may be localized.

You can also inject `E98\YouTrack\YouTrackClient` instead of using the facade.

### Custom fields

YouTrack needs a type for each custom field in a write request. `CustomField` provides `enum`, `multiEnum`, `state`, `user`, `owned`, `version`, `text` and `simple`. Field names and values must match your project's configuration exactly (case included), otherwise YouTrack answers with HTTP 400.

### Errors

All exceptions extend `E98\YouTrack\Exceptions\YouTrackException`:

| Exception | When |
|---|---|
| `ConfigurationException` | Missing base URL, token or project |
| `AuthenticationException` | HTTP 401, invalid or expired token |
| `ForbiddenException` | HTTP 403, missing permission |
| `NotFoundException` | HTTP 404, unknown issue or project |
| `ApiException` | Any other HTTP error (`->statusCode`, `->body`) |
| `YouTrackException` | Connection failures and other transport errors |

The message is taken from YouTrack's `error_description` when present.

### Testing your own code

```php
Http::fake([
    'youtrack.example.com/api/issues*' => Http::response([
        'id' => '2-45', 'idReadable' => 'SUP-1', 'summary' => 'x',
    ]),
]);
```

## Development

```bash
composer install
composer test
```

Tests use Orchestra Testbench and `Http::fake()`, so no YouTrack instance is needed.

### Release

```bash
git tag v0.1.1
git push origin v0.1.1
```

Composer resolves versions from git tags (semantic versioning). Packagist picks up new tags through the GitHub webhook; consumers then run `composer update e98/laravel-youtrack`.

## License

MIT. See [LICENSE](LICENSE).
