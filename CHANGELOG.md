# Release Notes for Pigeon

## Unreleased

### Added
- Reply by email. People can answer a conversation by replying to its notification email; the
  reply is posted as theirs. It's off by default. Switch it on and set a reply mailbox in the
  **Reply by email** section of Pigeon's settings, then point Postmark (basic auth), Mailgun
  (signed, with a replay window and single-use tokens) or SendGrid (ECDSA-signed raw body, or
  basic auth) at Pigeon's webhook. You can also poll an IMAP mailbox (`pigeon/inbound/poll`, needs ext-imap) or pipe mail
  to `pigeon/inbound/import -`.
- Each participant gets their own reply address (`messages+<tag>@…`), matched exactly, with the
  `In-Reply-To`/`References` headers as a fallback. A reply only posts if it comes from that
  participant's address and they could post the same message on the site or in the control panel:
  a guest with a live link, a user still on the thread, or staff with **Manage threads** (and the
  user-to-user permission for direct threads). Pigeon doesn't start conversations from email.
- Quoted history and signatures are stripped, HTML becomes escaped text, and out-of-office replies,
  bounces, mailing lists and Pigeon's own mail are ignored. Deliveries are deduplicated by
  Message-ID and limited per sender per hour. Attachments are screened against the attachment
  settings, with a content check, and anything refused is listed in an internal note.
- While reply by email is on, notification emails carry `Reply-To`, a Message-ID Pigeon
  remembers, and `Auto-Submitted`, and say that replying to them works.
- `pigeon/inbound/process`, `retry`, `log` and `prune` console commands, and
  `Inbound::EVENT_BEFORE_PROCESS` / `EVENT_AFTER_PROCESS`.

## 5.0.4 - 2026-10-05

> {warning} Staff with **Access Pigeon** no longer see user-to-user conversations. Grant the new
> **View private user-to-user conversations** permission to anyone who should. Attachments are now
> stored in `pigeon/<thread UID>/` inside the attachment volume and downloaded through Pigeon,
> which checks who is asking. Files uploaded before 5.0.4 stay in the volume root. If that volume
> has public URLs, those files are still public; move them or the volume somewhere private. If your
> own templates link attachments with `att.asset.url`, switch to
> `actionUrl('pigeon/attachments/download', { id: att.id, access: token })` (drop `access` for
> signed-in users).

### Security
- The guest rate limit was keyed on the visitor's address *and* the email they typed, so changing
  the email on every request was never limited. Every request sent a link email to the typed
  address with the visitor's subject in it. Guest threads and link requests now draw on three
  budgets: per client (the connecting address, with forwarded headers trusted only behind
  configured proxies, and IPv6 grouped by /64), per recipient, and site-wide. The guest-link email
  no longer includes the subject the visitor typed.
- Attachments were linked by their asset URL, so on a volume with public URLs a guest's upload was
  a public file. Links now go through `pigeon/attachments/download`, which serves a file to the
  guest holding that thread's link, a participant, or staff. Internal notes are staff-only, and
  files are always sent as downloads, never shown inline. Each thread's files go in a folder of
  their own. The settings screen warns when the attachment volume has public URLs.
- Anyone with **Access Pigeon** could read two users' private conversation in the control panel,
  including in the inbox listing, and opening any thread silently made the reader a participant.
  User-to-user threads now need **View private user-to-user conversations** to read, list, reply
  to, assign or change. Opening a thread only marks it read for someone already in it; staff join
  a thread by replying, adding a note, or being assigned.
- Guest forms redirected to the `Referer` header. They now go back to the page the form was on,
  or to a hashed `redirect` input.

### Changed
- The control panel conversation view and the inbox widget use Craft's own CSS variables, status
  labels and form fields instead of hard-coded colours and inline styles.
- `helpers\RateLimiter` is replaced by `helpers\RateLimit`.

## 5.0.3 - 2026-08-26

### Fixed

- **The threads index returned HTTP 500 whenever the status column was shown.** Craft 5 expects `statuses()` to return `craft\enums\Color` cases; the string colours this plugin returned made `Cp::componentStatusLabelHtml()` fail with "Attempt to read property `value` on string", leaving the inbox blank with no error shown.

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
