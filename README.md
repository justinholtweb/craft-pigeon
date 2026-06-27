# Pigeon

Two-way threaded messaging for Craft CMS 5. Pigeon gives your site a conversation inbox: customers ask a question, your team is notified and replies, and the customer is notified back — with the whole thread saved for both sides. It also supports private user-to-user direct messages between logged-in Craft users.

- **Guest ↔ admin support threads** — visitors message you without an account and return via a private, expiring link emailed to them.
- **User ↔ user direct messages** — logged-in users start conversations with each other.
- **Three notification channels** — queued email, a control-panel dashboard widget + nav badge, and on-site unread counts.
- **Built on a `Thread` element** — filterable CP inbox, search, statuses, and Trash for free.
- **Attachments, internal notes, assignment, statuses, read receipts.**

## Requirements

- Craft CMS 5.4.0 or later
- PHP 8.2 or later

## Installation

From your project directory:

```bash
composer require justinholtweb/pigeon
php craft plugin/install pigeon
```

Then visit **Settings → Plugins → Pigeon** to configure it.

## Concepts

- **Thread** — a conversation. Either `support` (guest/customer ↔ staff) or `direct` (user ↔ user). Has a status: `open`, `pending` (awaiting staff), or `closed`.
- **Participant** — a party on a thread. A Craft user (by `userId`) or a guest (by `email` + a hashed access token). Each participant tracks its own read state.
- **Message** — an entry in a thread. May be a normal message, an **internal note** (visible to staff only), or a system event.

## Notifications

Every new message fans out from one place. Other participants who opted in receive a queued email — staff get a control-panel link, users get a front-end link, and guests get a freshly minted token link. Run the queue to deliver:

```bash
php craft queue/run
```

Staff also see a **Pigeon Inbox** dashboard widget and a nav badge counting threads that need a reply.

## Front-end

Pigeon exposes `craft.pigeon` for logged-in users:

```twig
{{ craft.pigeon.unreadCount() }}            {# unread thread count #}
{% for thread in craft.pigeon.threads() %}
    <a href="{{ url('pigeon/threads/' ~ thread.id) }}">{{ thread.title }}</a>
{% endfor %}
{{ craft.pigeon.isUnread(threadId) }}
```

Built-in routes (self-contained example templates — copy and restyle as you like):

| Route | Who | Purpose |
|-------|-----|---------|
| `pigeon/threads` | logged-in user | List your threads + start one |
| `pigeon/threads/<id>` | logged-in user | View & reply |
| `pigeon/t/<token>` | guest | View & reply via emailed link |

### Public "contact support" form

Drop this anywhere (or copy `templates/_front/contact-form.twig`):

```twig
<form method="post" enctype="multipart/form-data">
    {{ csrfInput() }}
    {{ actionInput('pigeon/guest/start') }}
    <input type="text" name="name">
    <input type="email" name="email" required>
    <input type="text" name="subject">
    <textarea name="body" required></textarea>
    <input type="file" name="attachments[]" multiple>
    <button type="submit">Send</button>
</form>
```

The guest is emailed a private link to follow the conversation; your support recipients are alerted to the new thread.

## Action endpoints

| Action | Login | Purpose |
|--------|-------|---------|
| `pigeon/guest/start` | anonymous | Guest starts a support thread |
| `pigeon/guest/reply` | anonymous (token) | Guest replies |
| `pigeon/guest/request-link` | anonymous | Re-email an expired link |
| `pigeon/threads/start` | user | Start a support or direct thread |
| `pigeon/messages/reply` | user | Reply to a thread you're in |
| `pigeon/admin/reply` · `/status` · `/assign` | staff | Control-panel actions |

## Permissions

- **Access Pigeon** (`pigeon:accessPlugin`) — read the inbox.
- **Manage threads** (`pigeon:manageThreads`) — reply, add notes, change status.
  - **Assign threads** (`pigeon:assignThreads`).
- **Manage settings** (`pigeon:manageSettings`).

## Anti-spam

Guest forms are protected by a per-IP fixed-window rate limit and an optional honeypot field, both configurable in settings.

## License

See [LICENSE.md](LICENSE.md).
