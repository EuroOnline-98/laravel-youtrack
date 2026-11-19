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

foreach (YouTrack::issues()->attachments('SUP-123') as $file) {
    $file->name;       // "laravel.log"
    $file->size;       // bytes
    $file->mimeType;
    $file->author?->login;
    $file->created;    // CarbonImmutable
}

$file = YouTrack::issues()->attachments('SUP-123')->first();
YouTrack::issues()->saveAttachment($file, storage_path('app/laravel.log'));  // streams into the file
YouTrack::issues()->attachmentContents($file);                               // string, for small files
YouTrack::issues()->attachmentStream($file);                                 // PSR-7 stream
YouTrack::issues()->deleteAttachment('SUP-123', $file);                      // or the attachment id

// Tags and watchers
YouTrack::issues()->addTag('SUP-123', 'Release blocker');                  // by name, id or Tag
YouTrack::issues()->addTag('SUP-123', 'Release 0.3', create: true);        // creates the tag if missing
YouTrack::issues()->tags('SUP-123');                                       // Collection<Tag>
YouTrack::issues()->removeTag('SUP-123', 'Release blocker');
YouTrack::tags()->all();                                                   // the tags the token's user can see

YouTrack::issues()->watchers('SUP-123');                                   // Collection<Watcher> (->user, ->starred)
YouTrack::issues()->addWatcher('SUP-123', 'anna');
YouTrack::issues()->removeWatcher('SUP-123', 'anna');

// Projects
YouTrack::projects()->all();
YouTrack::projects()->find('SUP');

YouTrack::issues()->delete('SUP-123');
```

Notes on `command()`:

- Prefer `changeState()` for plain state changes; it sends a typed field value and does not depend on the command parser.
- Multi-word values are written without braces (`'State In Progress'`). On a YouTrack with a Hungarian UI the brace form (`'State {In Progress}'`) was rejected with HTTP 400.
- The command is parsed in the language of the token's user, so error messages may be localized.

Notes on attachments:

- `saveAttachment()` writes to `<path>.part` and renames it when the download is complete, so a failed download never leaves a partial file. The target directory must exist.
- Downloads use the attachment's `url`, sent with your token. The token is only ever sent to the configured YouTrack host; a URL on another host is refused.
- `deleteAttachment()` needs the permission to delete attachments, which the integration user may not have. It then fails with `ForbiddenException`.

Notes on tags and watchers:

- Tags are private to their owner unless they are shared in YouTrack. The package only sees and uses the tags of the token's user (and tags shared with them); a tag created with `create: true` is owned by that user.
- A tag is looked up by name (case-insensitive) or id. A name that looks like an id (`6-4`) is taken as an id; pass a `Tag` object for such names.
- YouTrack's REST API has no endpoint to change the watchers of an issue, so `addWatcher()` and `removeWatcher()` send the commands `star <login>` and `unstar <login>`. `removeWatcher()` only removes users who starred the issue; users who follow it for other reasons (reporter, assignee, commenter) keep getting notifications.

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

`YouTrack::fake()` replaces the client with an in-memory recording fake: nothing is sent over HTTP, no URL or token has to be configured, and the code under test gets the same `Issue` and `Comment` objects as in production. It swaps the facade and the container binding, so classes that inject `YouTrackClient` are covered too.

```php
use E98\YouTrack\Facades\YouTrack;

public function test_a_failed_payment_opens_a_ticket(): void
{
    $fake = YouTrack::fake();

    // ... run the code under test ...

    $fake->assertIssueCreated([
        'summary' => 'Payment failed',
        'project' => 'SUP',
        'fields' => ['Priority' => 'Critical'],
    ]);
    $fake->assertCommented('SUP-12', 'Retried the payment');
    $fake->assertStateChanged('SUP-12', 'In Progress');
}
```

Issues created through the fake are numbered per project (`SUP-1`, `SUP-2`, ...) and start in the state `Submitted`. Seed issues that already exist in YouTrack, for example the ids your database stores:

```php
YouTrack::fake()
    ->withIssue('SUP-12', ['summary' => 'Old problem', 'state' => 'Open'])
    ->withComment('SUP-12', 'Earlier comment', 'anna')
    ->withProject('SUP', 'Support');
```

Assertions: `assertIssueCreated()` (array or closure), `assertIssueCreatedTimes()`, `assertNoIssueCreated()`, `assertCommented()`, `assertNothingCommented()`, `assertStateChanged()`, `assertUpdated()`, `assertCommandSent()`, `assertAttached()`, `assertAttachmentDownloaded()`, `assertAttachmentDeleted()`, `assertDeleted()` and `assertNothingSent()` (reads count as well). Files can be seeded with `withAttachment('SUP-12', 'trace.log', 'contents')`; files attached through the fake can be listed and downloaded again.

Tags and watchers: seed with `withTag('Bug')`, `withTagOn('SUP-12', 'Bug')` and `withWatcher('SUP-12', 'anna')`, and assert with `assertTagged()`, `assertUntagged()`, `assertTagCreated()`, `assertWatcherAdded()` and `assertWatcherRemoved()`. A tag has to be registered with `withTag()` (or created with `create: true`), like in YouTrack. `recorded('comment')` returns the raw calls if you need your own checks.

What the fake does not do:

- `search()` is a plain, case-insensitive text match on id, summary and description; YouTrack's query language is not interpreted. Use `searchUsing(fn (string $query, int $limit, int $skip) => [...])` to answer a specific query.
- `command()` is recorded but has no effect on the stored issue.
- Reading or changing an issue that was neither created nor seeded throws `NotFoundException`, with a hint to seed it.
- The raw client methods (`get`, `post`, `request`, `upload`) throw, so a test cannot reach a real server by accident.
- The fake is no substitute for the live check: field names, state values and permissions are only validated by a real YouTrack.

If you prefer to fake the HTTP layer instead, `Http::fake()` works as well, since all requests go through Laravel's HTTP client:

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
