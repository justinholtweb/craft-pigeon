# Release Notes for Pigeon

## 5.0.2

### Fixed
- Guest access tokens now expire on time. Expiry timestamps are stored in UTC but were parsed in the system time zone, so an expired link stayed usable for the length of the site's UTC offset (and links expired early on sites ahead of UTC).
- Thread lists are now ordered deterministically. `craft.pigeon.threads()`, the front-end thread list, and the Pigeon Inbox widget sorted only by last-activity and creation timestamps, so threads touched within the same second came back in arbitrary order.

### Added
- Codeception test suite (142 tests) covering the services, `Thread` element and query, notification job and email templates, settings, helpers, and the install migration's schema. Run it with `composer test`.

### Changed
- PHPStan and ECS now analyze the test suite alongside `src/`.

## 5.0.1

### Fixed
- Control-panel thread list columns (status, type, starter, assignee) now render correctly. The `Thread` element overrode the Craft 4 `tableAttributeHtml()` method, which Craft 5 renamed to `attributeHtml()`; the override was never called, so columns fell back to defaults.

### Changed
- Added PHPStan (level 5) and ECS (Craft CMS coding standard) configuration and resolved all findings for type safety and code-style consistency.

## 5.0.0

### Added
- Threaded two-way messaging for Craft CMS 5.
- Guest ↔ admin support inboxes (no account required, signed token links) and user ↔ user direct messages.
- Control-panel inbox built on a `Thread` element: filter by status/type, conversation view, replies, internal staff-only notes, status changes, and assignment.
- Three notification channels: queued email (to the other party, with guest token links), a CP dashboard widget + nav badge, and on-site unread counts via `craft.pigeon`.
- File attachments stored as native Craft assets, with per-message count/size/extension limits.
- Per-participant read state (high-water mark) plus granular read receipts.
- Anti-spam: per-IP rate limiting and an optional honeypot on guest forms.
- Configurable settings: participation toggles, support recipients, from name/email, attachment volume + limits, guest token lifetime, and rate limits.
