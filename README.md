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

## Reply by email

Off by default. Switch it on under **Settings → Plugins → Pigeon → Reply by email**, set the
**Reply mailbox** (`messages@example.com`, or an environment variable) and connect one of the ways
in below. From then on, people can answer a conversation by replying to its notification email.

### How a reply finds its conversation

Every email Pigeon sends a participant goes out with a reply address carrying a tag that belongs to
**that participant**:

```
Reply-To: messages+k3v9q2…@example.com
```

The tag is 32 random lower-case letters and digits, separate from the guest's link, and is looked
up exactly. Every mainstream provider delivers `messages+anything@` to `messages@`. If a mail
client drops the tagged address, Pigeon falls back to the `In-Reply-To` and `References` headers:
every email it sends carries a Message-ID of its own, which it remembers. It never matches on the
subject line. Support alerts (sent to addresses, not participants) get the plain mailbox as their
`Reply-To`.

Pigeon takes **replies only**. Mail that isn't a reply to a conversation is refused and logged;
conversations still start on the site.

### Who may post

A reply only posts if its `From` is the address of the participant whose reply address it came
back to (a user's current account email, or a guest's address), and only if that person could post
the same message on the site or in the control panel right now:

- **A guest**: a support thread, still on it, and holding a live link. A guest's email access lapses
  on the same schedule as their link (**Guest token lifetime**) and comes back with the next email
  Pigeon sends them.
- **A user on the thread**: an active account that could sign in, still a participant.
- **Staff answering a support alert**: an active account with control panel access, **Access
  Pigeon** and **Manage threads**, and for a user-to-user thread **View private user-to-user
  conversations**. As with a reply from the control panel, they join the thread.

Anybody else is refused, even with the right reply address: the address is in every email its
participant was sent and can be forwarded. An emailed reply is never an internal note. It reopens
a closed thread exactly as the site's reply actions do, and notifies the other participants like
any other message.

### What happens to an email

1. **Verified.** The provider's signature or credentials are checked before anything is read. A
   provider with nothing configured is refused (HTTP 403), a wrong signature or password is 401.
2. **Stored and queued.** A Message-ID seen before is a duplicate and is dropped. A sender over the
   hourly limit is dropped. Attachments are screened against the attachment settings (volume,
   extensions, size, count), and only kept if the file's content matches its extension. The
   provider gets its 200 straight away; the rest happens in a queue job.
3. **Automation is ignored**: `Auto-Submitted` (anything but `no`), `Precedence: bulk/list/junk`,
   `X-Autoreply` and friends, mailing-list headers, bounces, and mail *from* the reply mailbox,
   Pigeon's From address or Craft's own. Pigeon marks its own notifications
   `Auto-Submitted: auto-generated` (the guest-link email `auto-replied`), so out-of-office replies
   leave them alone.
4. **Matched and checked**, as above.
5. **Quoted history and signatures are cut off** ("On … wrote:", Outlook's header block, `>`
   quotes, `-- `, "Sent from my iPhone"). HTML is never rendered: it's turned into text, links keep
   their address, and the text is escaped wherever it's shown.
6. **Files that were refused** are listed in an internal note, so staff know what was sent. Nobody
   is emailed about the note.

The last ten emails are listed on the settings screen; `php craft pigeon/inbound/log` shows more.

### Postmark

Postmark doesn't sign inbound webhooks; it authenticates with credentials in the URL. Set a
username and password in Pigeon, then use:

```
https://USERNAME:PASSWORD@example.com/actions/pigeon/inbound/postmark
```

### Mailgun

A route matching your reply mailbox with `forward("https://example.com/actions/pigeon/inbound/mailgun")`,
and your **HTTP webhook signing key** in Pigeon. Every post is HMAC-signed; Pigeon also refuses a
timestamp outside the **Replay window** (five minutes by default) and any token it has seen before.

### SendGrid

Inbound Parse to `https://example.com/actions/pigeon/inbound/sendgrid`. Preferably attach a
security policy with signature verification and paste its public key into Pigeon. SendGrid signs
the timestamp plus the raw body, and PHP throws away the raw body of a multipart post, so the
webhook must be served with `enable_post_data_reading = Off`, for example in nginx:

```nginx
location = /actions/pigeon/inbound/sendgrid {
    fastcgi_param PHP_VALUE "enable_post_data_reading=Off";
    # …your usual fastcgi_pass / include lines…
}
```

Without that, signed deliveries are refused and the log says why. On a host where you can't set
it, leave the key empty and use basic-auth credentials in the Parse URL instead.

### A mailbox (IMAP), or a pipe

Set the IMAP host, port, encryption, username, password and folder, and poll from cron:

```sh
*/2 * * * * php /path/to/craft pigeon/inbound/poll
```

A message is marked read (or deleted) only after it has been stored. The poll needs PHP's `imap`
extension (PECL on PHP 8.4+); without it the command says so and exits 78. Your own mail server
can pipe instead, with no provider and no extension:

```
messages: "|/usr/bin/php /path/to/craft pigeon/inbound/import -"
```

### Console

```sh
php craft pigeon/inbound/poll [--limit=50] [--sync]   # read the IMAP mailbox
php craft pigeon/inbound/import <file.eml|-> [--sync] # take in one raw message
php craft pigeon/inbound/process [--limit=100]        # handle queued emails now (no queue runner)
php craft pigeon/inbound/retry                        # re-queue emails that failed
php craft pigeon/inbound/log [--limit=20]             # what the last emails became
php craft pigeon/inbound/prune [--days=90]            # forget old ones
```

### Events

```php
use justinholtweb\pigeon\events\InboundEmailEvent;
use justinholtweb\pigeon\services\Inbound;
use yii\base\Event;

Event::on(Inbound::class, Inbound::EVENT_BEFORE_PROCESS, function(InboundEmailEvent $e) {
    // $e->email (parsed, past the loop guard and the sender check), $e->thread,
    // $e->participant / $e->user (who it will be posted as). $e->isValid = false drops it.
});

Event::on(Inbound::class, Inbound::EVENT_AFTER_PROCESS, function(InboundEmailEvent $e) {
    // $e->outcome: reply, ignored or rejected; $e->message is the posted reply.
});
```

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
| `pigeon/attachments/download` | any (checked) | An attachment, for whoever may read its message |
| `pigeon/inbound/postmark` · `/mailgun` · `/sendgrid` | anonymous (provider-signed) | Reply-by-email webhooks; 404 while the feature is off |

## Permissions

- **Access Pigeon** (`pigeon:accessPlugin`) — read the support inbox.
  - **View private user-to-user conversations** (`pigeon:viewDirectThreads`) — read, list and act
    on direct threads in the control panel. Without it they are not listed, and opening one is
    refused. Participants always see their own threads on the front end.
- **Manage threads** (`pigeon:manageThreads`) — reply, add notes, change status.
  - **Assign threads** (`pigeon:assignThreads`).
- **Manage settings** (`pigeon:manageSettings`).

## Anti-spam

Guest forms are protected by an optional honeypot field and rate limits configured in settings.
Starting a thread or requesting a link sends an email to an address the visitor typed, so each
draws on three budgets: per client, per recipient, and a site-wide ceiling of 20 times the
per-client limit. The client is the connecting address. `X-Forwarded-For` is only believed when
`trustedHosts` names your proxies, and IPv6 is grouped by /64. The guest-link email never includes
the subject the visitor typed.

## Attachments

Files are stored as assets in the attachment volume, in a folder per thread
(`pigeon/<thread UID>/`), and served through `pigeon/attachments/download`. That action checks
access: a guest needs their link's token (`access` param), a user must be in the thread, and staff
need inbox access. Internal-note files are staff-only, and files are always sent as downloads.
Pick a volume **without** public URLs: on a public volume the files are also reachable directly,
and the settings screen warns you.

## Development

The repo ships a [DDEV](https://ddev.com) environment and a Codeception suite that boots a real Craft installation against a scratch `pigeon_test` database.

```bash
ddev start
ddev exec composer test        # Codeception unit + integration suite
ddev exec composer phpstan     # static analysis (level 5)
ddev exec composer ecs-check   # coding standard
```

The suite drops and reinstalls the test database on every run, and each test runs in a transaction that is rolled back afterwards.

Reply by email has integration checks that run in a Craft install with Pigeon installed (they
create and remove their own users and threads). Every provider delivery is a fixture signed with
test secrets, so no provider account is needed:

```bash
php tests/integration/inbound.php        # parsers, signed Postmark/Mailgun/SendGrid fixtures, who may post
php tests/integration/inbound-http.php   # the webhooks over HTTP and pigeon/inbound/* (persists settings, restores them)
php tests/integration/security.php       # guest routes, attachments, inbox access
```

## License

See [LICENSE.md](LICENSE.md).
