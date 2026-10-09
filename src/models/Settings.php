<?php

namespace justinholtweb\pigeon\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

class Settings extends Model
{
    public const IMAP_SSL = 'ssl';
    public const IMAP_TLS = 'tls';
    public const IMAP_NONE = 'none';

    public const IMAP_MARK_SEEN = 'seen';
    public const IMAP_DELETE = 'delete';

    /**
     * Allow visitors without a Craft account to start support threads
     * (identified by email + a signed token link).
     */
    public bool $allowGuestThreads = true;

    /**
     * Allow logged-in Craft users to start direct (user-to-user) threads.
     */
    public bool $allowUserThreads = true;

    /**
     * Permission key required to start a direct thread, or empty for any
     * logged-in user. (Reserved for future use; not enforced in v1 core.)
     */
    public string $userThreadPermission = '';

    /**
     * Email addresses notified of new guest support threads when no admin is
     * yet assigned. Comma-separated or array. Falls back to the system email.
     *
     * @var string[]|string
     */
    public array|string $supportNotificationRecipients = '';

    /**
     * Default "from" name for Pigeon notification emails. Empty = system default.
     */
    public string $fromName = '';

    /**
     * Default "from" email for Pigeon notification emails. Empty = system default.
     */
    public string $fromEmail = '';

    /**
     * UID of the asset volume used to store message attachments. Empty disables
     * attachment uploads.
     */
    public string $attachmentVolumeUid = '';

    /**
     * Max number of attachments allowed per message.
     */
    public int $maxAttachmentsPerMessage = 5;

    /**
     * Max attachment size in megabytes.
     */
    public int $maxAttachmentSizeMb = 10;

    /**
     * Allowed attachment file extensions (lower-case, no dot).
     *
     * @var string[]
     */
    public array $allowedAttachmentExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'zip',
    ];

    /**
     * Days a guest access token stays valid, measured from the thread's last
     * activity (sliding expiry). Every notification email mints a fresh link.
     */
    public int $guestTokenLifetimeDays = 30;

    /**
     * Max messages a single IP may post within the rate-limit window.
     */
    public int $rateLimitMaxMessages = 10;

    /**
     * Rate-limit window in seconds.
     */
    public int $rateLimitWindowSeconds = 300;

    /**
     * Enable a hidden honeypot field on guest-facing forms.
     */
    public bool $enableHoneypot = true;

    /**
     * Name of the honeypot field. Bots that fill it are silently rejected.
     */
    public string $honeypotField = 'pigeon_hp';

    // Reply by email
    // -------------------------------------------------------------------------

    /**
     * Let people answer a conversation by replying to its notification email.
     *
     * Off by default: until a provider is pointed at the webhook, or a mailbox is set up for the
     * poll, there is nothing to receive — and the reply address only goes on outgoing mail while
     * this is on, so a site that never set it up never tells anybody to reply to a mailbox nobody
     * reads.
     */
    public bool $inboundEnabled = false;

    /**
     * The mailbox replies come back to — `messages@example.com`, or `$PIGEON_INBOUND_ADDRESS`.
     * Each participant's reply address is this with a tag: `messages+<token>@example.com`.
     */
    public string $inboundAddress = '';

    /** Emails one sender may send in an hour before the rest are dropped. Zero is unlimited. */
    public int $inboundRateLimit = 20;

    /**
     * Keep attachments that arrive by email, under the same rules as uploads — the attachment
     * volume, extensions, size and count.
     */
    public bool $inboundAttachments = true;

    /**
     * Seconds either side of now a signed timestamp may be (Mailgun, SendGrid). Long enough for
     * clock drift and a provider's own retry delay, short enough that a captured post is useless.
     */
    public int $inboundReplayWindow = 300;

    /** Other addresses the site sends as. Mail *from* them is never taken in. */
    public string $inboundIgnoreAddresses = '';

    public string $postmarkUsername = '';

    public string $postmarkPassword = '';

    public string $mailgunSigningKey = '';

    /** The public key from SendGrid's Parse security policy, as base64 or PEM. */
    public string $sendgridPublicKey = '';

    public string $sendgridUsername = '';

    public string $sendgridPassword = '';

    /** For `php craft pigeon/inbound/poll`. Needs PHP's imap extension, which most hosts lack. */
    public string $imapHost = '';

    public int $imapPort = 993;

    public string $imapEncryption = self::IMAP_SSL;

    public string $imapUsername = '';

    public string $imapPassword = '';

    public string $imapMailbox = 'INBOX';

    /** What happens to a message once it has been taken in: marked read, or deleted. */
    public string $imapAfterImport = self::IMAP_MARK_SEEN;

    public function defineRules(): array
    {
        return [
            [['allowGuestThreads', 'allowUserThreads', 'enableHoneypot'], 'boolean'],
            [['fromName', 'fromEmail', 'attachmentVolumeUid', 'userThreadPermission', 'honeypotField'], 'string'],
            [['fromEmail'], 'email', 'skipOnEmpty' => true],
            [['maxAttachmentsPerMessage'], 'integer', 'min' => 0, 'max' => 20],
            [['maxAttachmentSizeMb'], 'integer', 'min' => 1, 'max' => 200],
            [['guestTokenLifetimeDays'], 'integer', 'min' => 1, 'max' => 365],
            [['rateLimitMaxMessages'], 'integer', 'min' => 1],
            [['rateLimitWindowSeconds'], 'integer', 'min' => 1],
            [['inboundEnabled', 'inboundAttachments'], 'boolean'],
            [
                [
                    'inboundAddress', 'inboundIgnoreAddresses', 'postmarkUsername', 'postmarkPassword',
                    'mailgunSigningKey', 'sendgridPublicKey', 'sendgridUsername', 'sendgridPassword',
                    'imapHost', 'imapUsername', 'imapPassword', 'imapMailbox',
                ],
                'string',
            ],
            [['inboundRateLimit'], 'integer', 'min' => 0],
            [['inboundReplayWindow'], 'integer', 'min' => 30, 'max' => 3600],
            [['imapPort'], 'integer', 'min' => 1, 'max' => 65535],
            [['imapEncryption'], 'in', 'range' => [self::IMAP_SSL, self::IMAP_TLS, self::IMAP_NONE]],
            [['imapAfterImport'], 'in', 'range' => [self::IMAP_MARK_SEEN, self::IMAP_DELETE]],
            // Checked only when a value is set: a `required` rule would stop a fresh install
            // saving any setting at all.
            [['inboundAddress'], 'validateInboundAddress'],
        ];
    }

    /**
     * Resolved list of support notification email addresses.
     *
     * @return string[]
     */
    public function getSupportRecipients(): array
    {
        $value = $this->supportNotificationRecipients;

        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_filter(array_map('trim', $value)));
    }

    /**
     * A setting that may be an environment variable, resolved — or `''` when it names one that is
     * not set.
     *
     * `App::parseEnv('$NAME')` hands back `'$NAME'` itself when the variable is missing, and a
     * secret that is literally the string `$MAILGUN_KEY` is a secret anybody reading the docs
     * knows. Treated as unset, the provider refuses everything instead.
     */
    public function env(string $attribute): string
    {
        $raw = trim((string)($this->$attribute ?? ''));

        if ($raw === '') {
            return '';
        }

        if (preg_match('/^\$(\w+)$/', $raw, $m) === 1 && App::env($m[1]) === null) {
            return '';
        }

        return trim((string)App::parseEnv($raw));
    }

    /** @return list<string> The extra addresses mail is never taken from. */
    public function getIgnoredAddresses(): array
    {
        $parts = preg_split('/[\s,;]+/', $this->env('inboundIgnoreAddresses'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map(
            static fn(string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? strtolower($address) : '',
            $parts,
        )));
    }

    public function validateInboundAddress(string $attribute): void
    {
        $value = $this->env($attribute);

        if ($value === '') {
            return;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError($attribute, Craft::t('pigeon', 'The reply mailbox must be an email address.'));
        } elseif (str_contains(explode('@', $value)[0], '+')) {
            $this->addError($attribute, Craft::t('pigeon', 'Use the plain address, without a +tag. Pigeon adds the tag for each participant.'));
        }
    }
}
