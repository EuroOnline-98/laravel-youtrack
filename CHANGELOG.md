# Changelog

## 0.3.0 - 2026-11-19

Attachments, tags, watchers and issue links, all supported by `YouTrack::fake()`.

### Added

- **Attachments:** `issues()->attachments()` returns typed `Attachment` objects (id, name, size, MIME type, URL, author, created); `deleteAttachment()` takes an id or an `Attachment`; downloads via `attachmentContents()` (string), `saveAttachment()` (streams into a file through `<path>.part`, so a failed download never leaves a partial file) and `attachmentStream()` (PSR-7 stream).
- **Tags:** `YouTrack::tags()` (`all`, `find`, `create`, `resolve`), `issues()->tags()`, `addTag()` (by name, id or `Tag`, optionally creating the tag with `create: true`) and `removeTag()`.
- **Watchers:** `issues()->watchers()` returns typed `Watcher` objects (user, starred); `addWatcher()` and `removeWatcher()` use the `star` / `unstar` commands, because the REST API cannot change watchers.
- **Issue links:** `issues()->link()` and `unlink()` take a phrase such as `subtask of` or `relates to` (how the issue you link from relates to the other one), `links()` returns typed `Link` objects, `linkTypes()` lists the link types of the instance. An unknown phrase fails with the list of available ones and sends nothing.
- **Fake:** `YouTrack::fake()` supports all of the above: `withAttachment`, `withTag`, `withTagOn`, `withWatcher`, `withLink` and the assertions `assertAttachmentDownloaded`, `assertAttachmentDeleted`, `assertTagged`, `assertUntagged`, `assertTagCreated`, `assertWatcherAdded`, `assertWatcherRemoved`, `assertLinked`, `assertUnlinked`.
- `YouTrackClient::fetch()`: authenticated file download. The token is only sent to the configured YouTrack host; a URL on another host is refused.

### Changed

- The attachment `fields` selection now also asks for `created` and `author`, so `attach()` returns these as well.

### Notes

- Verified against a live YouTrack: attachments (upload, list, three download modes, delete), tags (create, add, list, remove), watchers (`star` / `unstar` for the token's own user) and links (directed and undirected, read from both ends, removed from either end).
- Tags are private to their owner unless shared in YouTrack; the package only sees the tags of the token's user. `removeWatcher()` only removes users who starred the issue.
- Known gap: `CustomField::version()` builds a single-value field, while the default `Affected versions` and `Fix versions` fields are multi-value; until a `multiVersion()` builder exists, pass the payload by hand (`['name' => 'Affected versions', '$type' => 'MultiVersionIssueCustomField', 'value' => [['name' => '0.2.0']]]`). New version values have to exist in the project's version list.

## 0.2.0 - 2026-11-18

- `YouTrack::fake()`: in-memory recording fake for tests of code that uses the package (no HTTP, no configuration needed), with seeding (`withIssue`, `withComment`, `withProject`) and assertions (`assertIssueCreated`, `assertCommented`, `assertStateChanged`, `assertNothingSent`, ...). See the README, "Testing your own code".
- `Issues` and `Projects` are no longer `final` (the fake extends them).

## 0.1.0 - 2026-11-18

- Initial version: issues (create, get, search, update, change state, delete, comments, commands, attachments), projects, `youtrack:ping` command, `CustomField` payload builders.
- API error messages are HTML-decoded (YouTrack escapes localized text); the raw body is unchanged.
- MIT license.
