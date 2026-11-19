# Changelog

## Unreleased

- Attachments: `attachments()` (typed `Attachment` objects), `deleteAttachment()`, `attachmentContents()`, `saveAttachment()` (atomic, via a `.part` file) and `attachmentStream()` (PSR-7).
- Tags: `YouTrack::tags()` (`all`, `find`, `create`, `resolve`), `issues()->tags()`, `addTag()` (by name, id or `Tag`; optionally creating the tag) and `removeTag()`.
- Watchers: `issues()->watchers()` (typed `Watcher` objects), `addWatcher()` and `removeWatcher()` (via the `star` / `unstar` commands, since the REST API cannot change watchers).
- `YouTrack::fake()` supports tags and watchers (`withTag`, `withTagOn`, `withWatcher`, `assertTagged`, `assertUntagged`, `assertTagCreated`, `assertWatcherAdded`, `assertWatcherRemoved`).
- Issue links: `issues()->link()` / `unlink()` take a phrase such as `subtask of` or `relates to`, `links()` returns typed `Link` objects, `linkTypes()` lists the types of the instance. `YouTrack::fake()` supports them (`withLink`, `assertLinked`, `assertUnlinked`).
- `YouTrackClient::fetch()`: authenticated file download; the token is only sent to the configured YouTrack host.
- `YouTrack::fake()` supports the new attachment methods (`withAttachment()`, `assertAttachmentDownloaded()`, `assertAttachmentDeleted()`).
- The attachment `fields` selection now also asks for `created` and `author`, so `attach()` returns these as well.

## 0.2.0 - 2026-11-18

- `YouTrack::fake()`: in-memory recording fake for tests of code that uses the package (no HTTP, no configuration needed), with seeding (`withIssue`, `withComment`, `withProject`) and assertions (`assertIssueCreated`, `assertCommented`, `assertStateChanged`, `assertNothingSent`, ...). See the README, "Testing your own code".
- `Issues` and `Projects` are no longer `final` (the fake extends them).

## 0.1.0 - 2026-11-18

- Initial version: issues (create, get, search, update, change state, delete, comments, commands, attachments), projects, `youtrack:ping` command, `CustomField` payload builders.
- API error messages are HTML-decoded (YouTrack escapes localized text); the raw body is unchanged.
- MIT license.
