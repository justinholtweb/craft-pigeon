<?php

namespace justinholtweb\pigeon\models;

use craft\base\Model;

class Settings extends Model
{
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
}
