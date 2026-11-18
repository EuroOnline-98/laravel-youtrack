# Changelog

## Unreleased

- `YouTrack::fake()`: in-memory recording fake for tests of code that uses the package (no HTTP, no configuration needed), with seeding (`withIssue`, `withComment`, `withProject`) and assertions (`assertIssueCreated`, `assertCommented`, `assertStateChanged`, `assertNothingSent`, ...). See the README, "Testing your own code".
- `Issues` and `Projects` are no longer `final` (the fake extends them).

## 0.1.0 - 2026-11-18

- Initial version: issues (create, get, search, update, change state, delete, comments, commands, attachments), projects, `youtrack:ping` command, `CustomField` payload builders.
- API error messages are HTML-decoded (YouTrack escapes localized text); the raw body is unchanged.
- MIT license.
