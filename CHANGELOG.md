# Release Notes for Pigeon

## 1.0.0

### Added
- Threaded two-way messaging for Craft CMS 5.
- Guest ↔ admin support inboxes (no account required, signed token links) and user ↔ user direct messages.
- Control-panel inbox built on a `Thread` element: filter by status/type, conversation view, replies, internal staff-only notes, status changes, and assignment.
- Three notification channels: queued email (to the other party, with guest token links), a CP dashboard widget + nav badge, and on-site unread counts via `craft.pigeon`.
- File attachments stored as native Craft assets, with per-message count/size/extension limits.
- Per-participant read state (high-water mark) plus granular read receipts.
- Anti-spam: per-IP rate limiting and an optional honeypot on guest forms.
- Configurable settings: participation toggles, support recipients, from name/email, attachment volume + limits, guest token lifetime, and rate limits.
