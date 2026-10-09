<?php

namespace justinholtweb\pigeon\services;

use Craft;
use craft\base\Component;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\mail\Message;
use DateTime;
use DateTimeZone;
use finfo;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\enums\ThreadType;
use justinholtweb\pigeon\events\InboundEmailEvent;
use justinholtweb\pigeon\helpers\AttachmentHelper;
use justinholtweb\pigeon\jobs\ProcessInboundEmail;
use justinholtweb\pigeon\mail\Addresses;
use justinholtweb\pigeon\mail\InboundAttachment;
use justinholtweb\pigeon\mail\InboundMessage;
use justinholtweb\pigeon\mail\InboundRequest;
use justinholtweb\pigeon\mail\LoopGuard;
use justinholtweb\pigeon\mail\MimeParser;
use justinholtweb\pigeon\mail\providers\Mailgun;
use justinholtweb\pigeon\mail\providers\Postmark;
use justinholtweb\pigeon\mail\providers\ProviderInterface;
use justinholtweb\pigeon\mail\providers\SendGrid;
use justinholtweb\pigeon\mail\ReplyParser;
use justinholtweb\pigeon\models\Settings;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\records\MessageRecord;
use justinholtweb\pigeon\records\ParticipantRecord;
use RuntimeException;
use Throwable;
use yii\db\IntegrityException;

/**
 * Reply by email: provider webhooks and an IMAP mailbox, turned into messages on a conversation.
 *
 * Ported from CSR's `services\Inbound` (the family reference — see "Inbound email" in CSR's
 * CLAUDE.md). Everything in `src/mail/` is CSR's, unchanged but for the namespace; the half of this
 * class above "What Pigeon does with it" is CSR's too, with Pigeon's table names and settings.
 *
 * ## Two halves, a queue between them
 *
 * {@see receive()} is the webhook: verify the provider's signature or credentials, parse, check
 * for a duplicate and the sender's rate, copy the attachments somewhere durable, write a row, queue
 * {@see ProcessInboundEmail}, answer 200. Nothing slow happens before the provider has its answer,
 * because a slow answer is a retry and every retry is another copy.
 *
 * {@see process()} is the job: the loop guard, finding the conversation (reply token first, then
 * the `In-Reply-To`/`References` headers), deciding who wrote it and whether they may post there,
 * and then posting it through {@see Messages::post()} like any other reply.
 *
 * ## Who may post
 *
 * A reply address belongs to one *participant*, not to the thread: `messages+<token>@…` is in the
 * emails sent to that participant and nobody else. A reply only posts when its `From` is that
 * participant's address, and when that participant could post the same message from the site or
 * the control panel right now — see {@see authorize()}. Pigeon never starts a conversation from
 * email: mail that is not a reply to one is refused and logged.
 */
class Inbound extends Component
{
    public const LOG_CATEGORY = 'pigeon';

    public const TABLE_INBOUND = '{{%pigeon_inbound}}';
    public const TABLE_EMAIL_THREADS = '{{%pigeon_email_threads}}';

    /** @see InboundEmailEvent Cancel it to drop the email, or change it. */
    public const EVENT_BEFORE_PROCESS = 'beforeProcess';

    /** @see InboundEmailEvent */
    public const EVENT_AFTER_PROCESS = 'afterProcess';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_REPLY = 'reply';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RATE_LIMITED = 'rateLimited';
    public const STATUS_FAILED = 'failed';

    public const RESULT_ACCEPTED = 'accepted';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_RATE_LIMITED = 'rateLimited';
    public const RESULT_REJECTED = 'rejected';

    /** The longest text and HTML kept from one email. Past this it is an attachment, not a reply. */
    public const MAX_TEXT_BYTES = 65536;
    public const MAX_HTML_BYTES = 262144;

    private const NONCE_KEY = 'pigeon:inbound:nonce:';

    // ------------------------------------------------------------------------------- switches

    public function isEnabled(): bool
    {
        return $this->settings()->inboundEnabled;
    }

    /** The reply mailbox, resolved, or null when it is not set to a usable address. */
    public function getAddress(): ?string
    {
        return Addresses::normalize($this->settings()->env('inboundAddress'));
    }

    /** @return array<string, ProviderInterface> */
    public function getProviders(): array
    {
        $settings = $this->settings();
        $cache = Craft::$app->getCache();

        return [
            Postmark::handle() => new Postmark(
                $settings->env('postmarkUsername'),
                $settings->env('postmarkPassword'),
            ),
            Mailgun::handle() => new Mailgun(
                $settings->env('mailgunSigningKey'),
                $settings->inboundReplayWindow,
                static fn(string $token, int $ttl): bool => $cache->add(self::NONCE_KEY . hash('sha256', $token), 1, $ttl),
            ),
            SendGrid::handle() => new SendGrid(
                $settings->env('sendgridPublicKey'),
                $settings->env('sendgridUsername'),
                $settings->env('sendgridPassword'),
                $settings->inboundReplayWindow,
            ),
        ];
    }

    public function getProvider(string $handle): ?ProviderInterface
    {
        return $this->getProviders()[$handle] ?? null;
    }

    /** @return array<string, string> Each provider's webhook URL — a front-end action URL. */
    public function webhookUrls(): array
    {
        $urls = [];

        foreach (array_keys($this->getProviders()) as $handle) {
            // Not a control panel URL: the provider is not logged in.
            $urls[$handle] = \craft\helpers\UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->actionTrigger . '/pigeon/inbound/' . $handle);
        }

        return $urls;
    }

    // ---------------------------------------------------------------------------- the webhook

    /** The live request, as the providers read it. */
    public function requestFromCraft(\craft\web\Request $request): InboundRequest
    {
        $headers = [];

        foreach ($request->getHeaders()->toArray() as $name => $values) {
            $headers[strtolower((string)$name)] = is_array($values) ? implode(', ', $values) : (string)$values;
        }

        [$user, $password] = $request->getAuthCredentials();
        $files = [];

        foreach ($_FILES as $name => $file) {
            // Single files only: no provider posts arrays of files, and nothing else is read.
            if (is_array($file) && is_string($file['tmp_name'] ?? null)) {
                $files[(string)$name] = [
                    'name' => (string)$file['name'],
                    'type' => (string)$file['type'],
                    'tmp_name' => $file['tmp_name'],
                    'size' => (int)$file['size'],
                    'error' => (int)$file['error'],
                ];
            }
        }

        return new InboundRequest(
            $headers,
            $request->getRawBody(),
            $_POST,
            $files,
            $user,
            $password,
            $this->tempDir(),
        );
    }

    /**
     * Verify, parse and accept one webhook delivery.
     *
     * @return array{status: int, result: string, id: int|null}
     */
    public function receive(string $handle, InboundRequest $request, ?int $now = null): array
    {
        $provider = $this->getProvider($handle);

        if ($provider === null) {
            return ['status' => 404, 'result' => 'unknown provider', 'id' => null];
        }

        // Refused, not waved through: an endpoint with no secret set is an endpoint anybody can
        // post replies to. 403 also tells Postmark to stop retrying.
        if (!$provider->isConfigured()) {
            Craft::warning("Inbound email from $handle was refused: no secret is configured for it.", self::LOG_CATEGORY);

            return ['status' => 403, 'result' => 'not configured', 'id' => null];
        }

        $reason = $provider->verify($request, $now ?? time());

        if ($reason !== null) {
            Craft::warning("Inbound email from $handle failed verification: $reason.", self::LOG_CATEGORY);
            $this->cleanupRequestFiles($request);

            return ['status' => 401, 'result' => 'unauthorized', 'id' => null];
        }

        try {
            $message = $provider->parse($request);
        } catch (Throwable $exception) {
            Craft::warning("Inbound email from $handle could not be parsed: " . $exception->getMessage(), self::LOG_CATEGORY);
            $this->cleanupRequestFiles($request);

            return ['status' => 400, 'result' => 'unreadable', 'id' => null];
        }

        $accepted = $this->accept($message);

        return ['status' => 200, 'result' => $accepted['result'], 'id' => $accepted['id']];
    }

    /**
     * A raw RFC 822 message — from the IMAP poll, `pigeon/inbound/import`, or a pipe from the MTA.
     *
     * @return array{result: string, id: int|null}
     */
    public function importRaw(string $raw, string $provider = 'import', bool $queue = true): array
    {
        $request = new InboundRequest(tempDir: $this->tempDir());
        $message = MimeParser::parse($raw, $request->writeTemp(...), $provider);

        return $this->accept($message, $queue);
    }

    /**
     * Take a parsed message in: dedupe, rate-limit, screen the attachments, store, queue.
     *
     * @return array{result: string, id: int|null}
     */
    public function accept(InboundMessage $message, bool $queue = true): array
    {
        $hash = $message->dedupeHash();
        $existing = (new Query())->select(['id'])->from([self::TABLE_INBOUND])->where(['messageHash' => $hash])->scalar();

        if ($existing !== false && $existing !== null) {
            $this->deleteFiles($message->attachments);

            return ['result' => self::RESULT_DUPLICATE, 'id' => (int)$existing];
        }

        if ($message->fromEmail === '' || Addresses::normalize($message->fromEmail) === null) {
            $this->deleteFiles($message->attachments);
            $id = $this->insertRow($message, $hash, self::STATUS_REJECTED, 'no usable sender address', null);

            return ['result' => self::RESULT_REJECTED, 'id' => $id];
        }

        if ($this->isRateLimited($message->fromEmail)) {
            $this->deleteFiles($message->attachments);
            $id = $this->insertRow($message, $hash, self::STATUS_RATE_LIMITED, 'more than the hourly limit from this sender', null);
            Craft::warning("Inbound email from {$message->fromEmail} was rate limited.", self::LOG_CATEGORY);

            return ['result' => self::RESULT_RATE_LIMITED, 'id' => $id];
        }

        $message->text = mb_strcut($message->text, 0, self::MAX_TEXT_BYTES, 'UTF-8');
        $message->html = mb_strcut($message->html, 0, self::MAX_HTML_BYTES, 'UTF-8');

        [$kept, $dropped] = $this->screenAttachments($message->attachments);
        $message->attachments = $this->moveToStorage($kept, $hash);

        $payload = $message->toArray();
        $payload['dropped'] = $dropped;

        $id = $this->insertRow($message, $hash, self::STATUS_QUEUED, null, $payload);

        if ($id === null) {
            // Lost a race with another delivery of the same message.
            $this->deleteFiles($message->attachments);
            $existing = (new Query())->select(['id'])->from([self::TABLE_INBOUND])->where(['messageHash' => $hash])->scalar();

            return ['result' => self::RESULT_DUPLICATE, 'id' => $existing !== false ? (int)$existing : null];
        }

        if ($queue) {
            Queue::push(new ProcessInboundEmail(['inboundId' => $id]));
        } else {
            $this->process($id);
        }

        return ['result' => self::RESULT_ACCEPTED, 'id' => $id];
    }

    /** @param array<string, mixed>|null $payload */
    private function insertRow(InboundMessage $message, string $hash, string $status, ?string $reason, ?array $payload): ?int
    {
        try {
            Db::insert(self::TABLE_INBOUND, [
                'provider' => mb_substr($message->provider ?: 'unknown', 0, 16),
                'messageHash' => $hash,
                'messageId' => $message->messageId !== null ? mb_substr($message->messageId, 0, 255) : null,
                'fromEmail' => mb_substr(strtolower($message->fromEmail), 0, 255),
                'subject' => mb_substr($message->subject, 0, 255),
                'status' => $status,
                'reason' => $reason,
                'payload' => $payload !== null ? Json::encode($payload) : null,
                'dateProcessed' => $status === self::STATUS_QUEUED ? null : Db::prepareDateForDb(new DateTime()),
            ]);
        } catch (IntegrityException) {
            return null;
        }

        // Read back by the unique key rather than `getLastInsertID()`, which needs a sequence
        // name on Postgres.
        return (int)(new Query())->select(['id'])->from([self::TABLE_INBOUND])->where(['messageHash' => $hash])->scalar();
    }

    /** Whether this sender has already sent the hourly limit. */
    public function isRateLimited(string $fromEmail): bool
    {
        $limit = $this->settings()->inboundRateLimit;

        if ($limit <= 0) {
            return false;
        }

        $count = (int)(new Query())
            ->from([self::TABLE_INBOUND])
            ->where(['fromEmail' => strtolower($fromEmail)])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb(new DateTime('-1 hour'))])
            ->count();

        return $count >= $limit;
    }

    // ------------------------------------------------------------------------------ attachments

    /**
     * Which attachments may be kept, under the same rules as an upload: a volume chosen, an allowed
     * extension, under the size limit, no more than the count — and content that is what its
     * extension says it is, because `invoice.pdf` that is really a Windows executable is exactly
     * what arrives at a public address.
     *
     * @param list<InboundAttachment> $attachments
     * @return array{0: list<InboundAttachment>, 1: list<array{filename: string, reason: string}>}
     */
    public function screenAttachments(array $attachments): array
    {
        $settings = $this->settings();
        $kept = [];
        $dropped = [];
        $enabled = $settings->inboundAttachments && $settings->attachmentVolumeUid !== '' && $settings->maxAttachmentsPerMessage > 0;
        $allowed = array_map('strtolower', $settings->allowedAttachmentExtensions);
        $maxSize = $settings->maxAttachmentSizeMb * 1024 * 1024;
        $maxCount = $settings->maxAttachmentsPerMessage;

        foreach ($attachments as $attachment) {
            $reason = match (true) {
                !$enabled => 'attachments are off',
                !in_array($attachment->extension(), $allowed, true) => 'file type not allowed',
                $attachment->size <= 0 || !is_file($attachment->path) => 'empty file',
                $attachment->size > $maxSize || filesize($attachment->path) > $maxSize => 'too large',
                count($kept) >= $maxCount => 'too many attachments',
                !self::contentMatchesExtension($attachment->path, $attachment->extension()) => 'content does not match its extension',
                default => null,
            };

            if ($reason === null) {
                $kept[] = $attachment;
                continue;
            }

            $dropped[] = ['filename' => mb_substr($attachment->filename, 0, 200), 'reason' => $reason];
            @unlink($attachment->path);
        }

        return [$kept, $dropped];
    }

    /** Sniff the bytes and ask whether that kind of file is allowed to carry this extension. */
    public static function contentMatchesExtension(string $path, string $extension): bool
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';

        if (in_array($extension, FileHelper::getExtensionsByMimeType($mime), true)) {
            return true;
        }

        // libmagic's names for the formats it cannot name more precisely.
        $families = [
            'text/' => ['txt', 'log', 'csv', 'md', 'json', 'xml', 'eml', 'ics', 'vcf'],
            'application/zip' => ['zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'pages', 'numbers', 'key'],
            'application/x-ole-storage' => ['doc', 'xls', 'ppt', 'msg'],
            'application/cdfv2' => ['doc', 'xls', 'ppt', 'msg'],
            'message/rfc822' => ['eml'],
            'image/jpeg' => ['jpg', 'jpeg', 'jfif'],
        ];

        foreach ($families as $prefix => $extensions) {
            if (str_starts_with($mime, $prefix) && in_array($extension, $extensions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copy kept attachments out of the request's temp space into storage that outlives it.
     *
     * @param list<InboundAttachment> $attachments
     * @return list<InboundAttachment>
     */
    private function moveToStorage(array $attachments, string $hash): array
    {
        if ($attachments === []) {
            return [];
        }

        $dir = $this->storageDir() . DIRECTORY_SEPARATOR . substr($hash, 0, 24);
        FileHelper::createDirectory($dir);
        $moved = [];

        foreach ($attachments as $index => $attachment) {
            $target = $dir . DIRECTORY_SEPARATOR . $index;

            if (@copy($attachment->path, $target)) {
                @unlink($attachment->path);
                $attachment->path = $target;
                $moved[] = $attachment;
            }
        }

        return $moved;
    }

    /** @param list<InboundAttachment> $attachments */
    private function deleteFiles(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if ($attachment->path !== '' && is_file($attachment->path)) {
                @unlink($attachment->path);
            }
        }
    }

    private function cleanupRequestFiles(InboundRequest $request): void
    {
        foreach ($request->files as $file) {
            if (is_file($file['tmp_name'])) {
                @unlink($file['tmp_name']);
            }
        }
    }

    public function storageDir(): string
    {
        return Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . 'pigeon-inbound';
    }

    private function tempDir(): string
    {
        return Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'pigeon-inbound';
    }

    // ============================================================ What Pigeon does with it
    //
    // Everything below is Pigeon's. process() keeps CSR's shape — claim the row, loop guard, find,
    // verify the sender, event, act, record, clean up — and "act" is posting a reply.

    /**
     * Turn one stored email into a reply. Idempotent: anything no longer queued is left as it is.
     *
     * @return string The row's status afterwards.
     */
    public function process(int $id): string
    {
        $row = (new Query())->from([self::TABLE_INBOUND])->where(['id' => $id])->one();

        if (!$row) {
            return self::STATUS_FAILED;
        }

        if ($row['status'] !== self::STATUS_QUEUED) {
            return (string)$row['status'];
        }

        // Claim it, so two workers picking up two copies of the job do the work once.
        if (Db::update(self::TABLE_INBOUND, ['status' => 'processing'], ['id' => $id, 'status' => self::STATUS_QUEUED]) !== 1) {
            return (string)(new Query())->select(['status'])->from([self::TABLE_INBOUND])->where(['id' => $id])->scalar();
        }

        $payload = Json::decodeIfJson((string)$row['payload']);
        $payload = is_array($payload) ? $payload : [];
        $email = InboundMessage::fromArray($payload);
        $dropped = is_array($payload['dropped'] ?? null) ? $payload['dropped'] : [];

        try {
            [$status, $reason, $thread, $posted] = $this->handle($email, $id, $dropped);
        } catch (Throwable $exception) {
            Craft::error("Inbound email #$id could not be processed: " . $exception->getMessage(), self::LOG_CATEGORY);
            Db::update(self::TABLE_INBOUND, [
                'status' => self::STATUS_FAILED,
                'reason' => mb_substr($exception->getMessage(), 0, 255),
                'attempts' => new \yii\db\Expression('[[attempts]] + 1'),
                'dateProcessed' => Db::prepareDateForDb(new DateTime()),
            ], ['id' => $id]);

            // Kept for `pigeon/inbound/retry`: a failure is usually the database or the volume, and
            // the email is still worth having once that is fixed.
            return self::STATUS_FAILED;
        }

        $this->deleteFiles($email->attachments);
        @rmdir($this->storageDir() . DIRECTORY_SEPARATOR . substr((string)$row['messageHash'], 0, 24));

        Db::update(self::TABLE_INBOUND, [
            'status' => $status,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'threadId' => $thread?->id,
            'postedMessageId' => $posted?->id,
            'attempts' => new \yii\db\Expression('[[attempts]] + 1'),
            'dateProcessed' => Db::prepareDateForDb(new DateTime()),
            // The content has done its job; the row stays as the record of what happened.
            'payload' => null,
        ], ['id' => $id]);

        if ($this->hasEventHandlers(self::EVENT_AFTER_PROCESS)) {
            $this->trigger(self::EVENT_AFTER_PROCESS, new InboundEmailEvent([
                'email' => $email,
                'inboundId' => $id,
                'thread' => $thread,
                'message' => $posted,
                'outcome' => $status,
            ]));
        }

        return $status;
    }

    /**
     * @param list<array{filename: string, reason: string}> $dropped
     * @return array{0: string, 1: string|null, 2: Thread|null, 3: MessageRecord|null}
     */
    private function handle(InboundMessage $email, int $id, array $dropped): array
    {
        $loop = LoopGuard::reason($email, $this->ownAddresses());

        if ($loop !== null) {
            return [self::STATUS_IGNORED, 'automated mail: ' . $loop, null, null];
        }

        [$thread, $participant, $via] = $this->findThread($email);

        if ($thread === null) {
            // Pigeon takes replies, not new conversations: those start on the site.
            return [self::STATUS_REJECTED, 'not a reply to a conversation', null, null];
        }

        $author = $this->authorize($thread, $participant, $email);

        if (is_string($author)) {
            return [self::STATUS_REJECTED, $author, $thread, null];
        }

        $event = new InboundEmailEvent([
            'email' => $email,
            'inboundId' => $id,
            'thread' => $thread,
            'participant' => $author['participant'],
            'user' => $author['user'],
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_PROCESS)) {
            $this->trigger(self::EVENT_BEFORE_PROCESS, $event);
        }

        if (!$event->isValid) {
            return [self::STATUS_IGNORED, 'cancelled by an event handler', $thread, null];
        }

        return $this->postReply($thread, $author, $email, $dropped, (string)$via);
    }

    /**
     * Post the reply exactly as the site or the control panel would have.
     *
     * @param array{participant: ParticipantRecord|null, user: User|null, path: string} $author
     * @param list<array{filename: string, reason: string}> $dropped
     * @return array{0: string, 1: string|null, 2: Thread|null, 3: MessageRecord|null}
     */
    private function postReply(Thread $thread, array $author, InboundMessage $email, array $dropped, string $via): array
    {
        $plugin = Plugin::getInstance();
        $text = ReplyParser::newText($email->text, $email->html);

        if ($text === '' && $email->attachments === []) {
            return [self::STATUS_IGNORED, 'nothing new in the reply', $thread, null];
        }

        $assetIds = [];

        foreach ($email->attachments as $attachment) {
            $assetId = AttachmentHelper::saveFile($attachment->path, $attachment->filename, $thread);

            if ($assetId !== null) {
                $assetIds[] = $assetId;
            } else {
                $dropped[] = ['filename' => mb_substr($attachment->filename, 0, 200), 'reason' => 'could not be stored'];
            }
        }

        if ($text === '' && $assetIds === []) {
            $this->noteDropped($thread, $dropped);

            return [self::STATUS_IGNORED, 'nothing new in the reply', $thread, null];
        }

        $user = $author['user'];
        $participant = $author['participant'];

        // The same reopening the site's reply actions do; the control panel's leaves it closed.
        if ($thread->threadStatus === ThreadStatus::Closed->value) {
            if ($author['path'] === 'guest') {
                $plugin->threads->setStatus($thread, ThreadStatus::Pending);
            } elseif ($author['path'] === 'participant') {
                $plugin->threads->setStatus($thread, ThreadStatus::Open);
            }
        }

        // Never an internal note, whatever the email says: notes are written in the control panel.
        $config = $user !== null
            ? ['authorUserId' => (int)$user->id, 'authorName' => (string)$user]
            : ['authorEmail' => $participant?->email, 'authorName' => $participant?->name];

        $posted = $plugin->messages->post($thread, $config + [
            'body' => $text,
            'attachmentAssetIds' => $assetIds,
            'isInternalNote' => false,
        ]);

        $this->noteDropped($thread, $dropped);
        $this->recordThread($thread, $participant, $email->messageId, 'in');

        return [self::STATUS_REPLY, 'matched by ' . $via, $thread, $posted];
    }

    /**
     * Which conversation an email belongs to, whose reply address it came back to, and how that
     * was decided.
     *
     * @return array{0: Thread|null, 1: ParticipantRecord|null, 2: string|null}
     */
    public function findThread(InboundMessage $email): array
    {
        $base = $this->getAddress();
        $tokens = [];

        if ($base !== null) {
            foreach ($email->allRecipients() as $recipient) {
                $token = Addresses::tokenFrom($recipient, $base);

                if ($token !== null) {
                    $tokens[] = $token;
                }
            }
        }

        if ($email->mailboxHash !== null && Addresses::isToken(strtolower($email->mailboxHash))) {
            $tokens[] = strtolower($email->mailboxHash);
        }

        $participants = Plugin::getInstance()->participants;
        $from = Addresses::normalize($email->fromEmail);
        $first = null;

        // Several tagged addresses (a reply-all that kept another participant's) — the sender's
        // own wins; otherwise the first, which the sender check will then refuse.
        foreach (array_unique($tokens) as $token) {
            $participant = $participants->findByReplyToken($token);
            $thread = $participant !== null ? Plugin::getInstance()->threads->getById((int)$participant->threadId) : null;

            if ($thread === null) {
                continue;
            }

            if ($from !== null && $this->participantAddressIs($participant, $from)) {
                return [$thread, $participant, 'reply address'];
            }

            $first ??= [$thread, $participant, 'reply address'];
        }

        if ($first !== null) {
            return $first;
        }

        $hashes = array_map(static fn(string $id) => Addresses::messageHash($id), $email->threadIds());

        if ($hashes !== []) {
            $row = (new Query())
                ->select(['threadId', 'participantId'])
                ->from([self::TABLE_EMAIL_THREADS])
                ->where(['messageHash' => $hashes])
                ->orderBy(['id' => SORT_DESC])
                ->one();

            if ($row) {
                $thread = Plugin::getInstance()->threads->getById((int)$row['threadId']);
                $participant = $row['participantId'] !== null ? ParticipantRecord::findOne((int)$row['participantId']) : null;

                if ($thread !== null) {
                    return [$thread, $participant, 'email headers'];
                }
            }
        }

        return [null, null, null];
    }

    /**
     * Who wrote this reply, and whether they may post it — or why not.
     *
     * The email's `From` must be the address of the participant whose reply address it came back
     * to (or, matched by headers alone on a staff alert, of someone on the thread or on the staff).
     * Then the same checks the web actions make:
     *
     * - a **guest** (`GuestController::actionReply`) — a support thread, still on it, and holding a
     *   live link: the guest's access lapses on the same schedule by email as on the site;
     * - a **user on the thread** (`MessagesController::actionReply`) — an active account that could
     *   sign in, still a participant;
     * - **staff** (`AdminController::actionReply`) — an active account with control panel access,
     *   **Access Pigeon** and **Manage threads**, and for a user-to-user thread **View private
     *   user-to-user conversations**.
     *
     * The reply address is in every email its participant was sent and can be forwarded: mail from
     * anybody else never joins the conversation.
     *
     * @return array{participant: ParticipantRecord|null, user: User|null, path: string}|string
     */
    public function authorize(Thread $thread, ?ParticipantRecord $bound, InboundMessage $email): array|string
    {
        $from = Addresses::normalize($email->fromEmail);

        if ($from === null) {
            return 'no usable sender address';
        }

        if ($bound !== null && (int)$bound->threadId !== (int)$thread->id) {
            $bound = null;
        }

        if ($bound !== null) {
            if (!$this->participantAddressIs($bound, $from)) {
                return 'sent to a participant’s reply address by somebody else';
            }

            $candidates = [$bound];
        } else {
            $candidates = array_values(array_filter(
                Plugin::getInstance()->participants->getForThread((int)$thread->id),
                fn(ParticipantRecord $p) => $this->participantAddressIs($p, $from),
            ));
        }

        foreach ($candidates as $participant) {
            if ($participant->userId === null) {
                $refusal = $this->guestMayPost($thread, $participant);

                if ($refusal === null) {
                    return ['participant' => $participant, 'user' => null, 'path' => 'guest'];
                }

                continue;
            }

            $user = Craft::$app->getUsers()->getUserById((int)$participant->userId);

            if ($user === null || !$this->canSignIn($user)) {
                continue;
            }

            if ($participant->leftAt === null) {
                return ['participant' => $participant, 'user' => $user, 'path' => 'participant'];
            }

            if ($this->staffMayPost($user, $thread)) {
                return ['participant' => $participant, 'user' => $user, 'path' => 'staff'];
            }
        }

        // Staff who are not on the thread — answering the support alert, which goes to addresses
        // rather than participants — reply as themselves, if they could from the control panel.
        if ($bound === null) {
            $staff = $this->userByEmail($from);

            if ($staff !== null && $this->staffMayPost($staff, $thread)) {
                return ['participant' => null, 'user' => $staff, 'path' => 'staff'];
            }
        }

        return $bound !== null && $bound->userId === null
            ? ($this->guestMayPost($thread, $bound) ?? 'not allowed to post to this conversation')
            : 'not allowed to post to this conversation';
    }

    /** Why a guest may not post to this thread right now, or null when they may. */
    private function guestMayPost(Thread $thread, ParticipantRecord $participant): ?string
    {
        if ($thread->type !== ThreadType::Support->value) {
            return 'guests only take part in support conversations';
        }

        if ($participant->leftAt !== null) {
            return 'the guest has left the conversation';
        }

        // The guest's link lapses after the configured days without activity; their email access
        // goes with it, and comes back with the next email sent to them (or a requested link).
        if ($participant->tokenExpiresAt === null
            || new DateTime($participant->tokenExpiresAt, new DateTimeZone('UTC')) < new DateTime('now', new DateTimeZone('UTC'))
        ) {
            return 'the guest’s access to this conversation has expired';
        }

        return null;
    }

    /** The control panel's reply action, asked of somebody who is not signed in. */
    private function staffMayPost(User $user, Thread $thread): bool
    {
        return $this->canSignIn($user)
            && $user->can('accessCp')
            && $user->can('pigeon:manageThreads')
            && Plugin::canViewInCp($user, $thread);
    }

    private function canSignIn(User $user): bool
    {
        return $user->getStatus() === User::STATUS_ACTIVE;
    }

    /** Whether `$from` (normalised) is this participant's address — a user's *current* one. */
    private function participantAddressIs(ParticipantRecord $participant, string $from): bool
    {
        if ($participant->userId !== null) {
            $user = Craft::$app->getUsers()->getUserById((int)$participant->userId);
            $address = Addresses::normalize($user?->email);
        } else {
            $address = Addresses::normalize($participant->email);
        }

        return $address !== null && hash_equals($address, $from);
    }

    /**
     * The account with exactly this address. Not `getUserByUsernameOrEmail()` — a username may
     * look like anybody's address — and not the `email` query param, which reads `*` as a wildcard.
     */
    private function userByEmail(string $from): ?User
    {
        $id = (new Query())
            ->select(['id'])
            ->from([CraftTable::USERS])
            ->where(new \yii\db\Expression('LOWER([[email]]) = :pigeonFrom', [':pigeonFrom' => $from]))
            ->scalar();

        if ($id === false || $id === null) {
            return null;
        }

        $user = Craft::$app->getUsers()->getUserById((int)$id);

        return $user !== null && hash_equals(strtolower((string)$user->email), $from) ? $user : null;
    }

    /** @param list<array{filename: string, reason: string}> $dropped */
    private function noteDropped(Thread $thread, array $dropped): void
    {
        if ($dropped === []) {
            return;
        }

        $lines = array_map(static fn(array $file) => '- ' . $file['filename'] . ' — ' . $file['reason'], $dropped);

        // An internal note, so staff know what was sent — and nobody is emailed about it.
        Plugin::getInstance()->messages->post($thread, [
            'body' => Craft::t('pigeon', 'Attachments in an emailed reply that were not kept:') . "\n" . implode("\n", $lines),
            'isInternalNote' => true,
            'notify' => false,
        ]);
    }

    /** @return list<string> Every address mail must never be taken from. */
    public function ownAddresses(): array
    {
        $settings = $this->settings();
        $own = $settings->getIgnoredAddresses();

        foreach ([$this->getAddress(), Addresses::normalize($settings->fromEmail), Addresses::normalize(App::parseEnv((string)App::mailSettings()->fromEmail))] as $address) {
            if ($address !== null) {
                $own[] = $address;
            }
        }

        return array_values(array_unique($own));
    }

    // -------------------------------------------------------------------------- email going out

    /**
     * `messages+<token>@example.com` for this participant — or the plain mailbox for mail that
     * goes to an address rather than a participant — when reply by email is on.
     */
    public function replyAddressFor(?ParticipantRecord $participant): ?string
    {
        $base = $this->getAddress();

        if (!$this->isEnabled() || $base === null) {
            return null;
        }

        if ($participant === null) {
            return $base;
        }

        return Addresses::replyAddress($base, Plugin::getInstance()->participants->replyTokenFor($participant));
    }

    /**
     * Address an email so its reply comes back to the conversation.
     *
     * The reply address goes in `Reply-To`; a Message-ID of Pigeon's own is set and remembered, so
     * a client that drops the tagged address but keeps `In-Reply-To` still finds the conversation;
     * the first email in this participant's exchange is referenced so their client threads them;
     * and the email says it was machine-sent (RFC 3834) so an out-of-office leaves it alone.
     * Nothing is added while reply by email is off.
     */
    public function prepareOutgoing(\yii\mail\MessageInterface $mail, Thread $thread, ?ParticipantRecord $participant, bool $automatic = false): void
    {
        // Craft's mailer composes `craft\mail\Message`; anything else has no headers to set.
        if (!$mail instanceof Message) {
            return;
        }

        $address = $this->replyAddressFor($participant);

        if ($address === null || $thread->id === null) {
            return;
        }

        $mail->setReplyTo($address);
        $headers = $mail->getSymfonyEmail()->getHeaders();
        $domain = explode('@', $address, 2)[1];
        $messageId = 'pigeon.' . bin2hex(random_bytes(12)) . '@' . $domain;

        $headers->remove('Message-ID');
        $headers->addIdHeader('Message-ID', $messageId);

        $first = (new Query())
            ->select(['messageId'])
            ->from([self::TABLE_EMAIL_THREADS])
            ->where(['threadId' => $thread->id, 'participantId' => $participant?->id])
            ->andWhere(['not', ['messageId' => null]])
            ->orderBy(['id' => SORT_ASC])
            ->scalar();

        if (is_string($first) && $first !== '' && !$headers->has('In-Reply-To')) {
            $headers->addIdHeader('In-Reply-To', $first);
            $headers->addIdHeader('References', [$first]);
        }

        if (!$headers->has('Auto-Submitted')) {
            // `auto-replied` answers something the recipient did; `auto-generated` is a notice.
            $headers->addTextHeader('Auto-Submitted', $automatic ? 'auto-replied' : 'auto-generated');
        }

        $this->recordThread($thread, $participant, $messageId, 'out');
    }

    /** Remember that a Message-ID belongs to a conversation (and to whom it was sent, or by whom). */
    public function recordThread(Thread $thread, ?ParticipantRecord $participant, ?string $messageId, string $direction): void
    {
        $messageId = Addresses::normalizeMessageId($messageId);

        if ($messageId === null || $thread->id === null) {
            return;
        }

        try {
            Db::insert(self::TABLE_EMAIL_THREADS, [
                'threadId' => $thread->id,
                'participantId' => $participant?->id,
                'messageHash' => Addresses::messageHash($messageId),
                'messageId' => mb_substr($messageId, 0, 255),
                'direction' => $direction === 'out' ? 'out' : 'in',
            ]);
        } catch (IntegrityException) {
            // Already known. The first conversation to claim a Message-ID keeps it.
        }
    }

    // ----------------------------------------------------------------------------------- IMAP

    /** Whether the poll can run on this PHP at all. */
    public static function imapAvailable(): bool
    {
        return function_exists('imap_open');
    }

    /**
     * Take unread messages from the configured mailbox.
     *
     * Each message is marked read (or deleted) only after it has been stored, so a failure halfway
     * through leaves the rest for the next run rather than losing them.
     *
     * @return array{fetched: int, accepted: int, duplicates: int, rejected: int}
     * @throws RuntimeException when the extension is missing or the mailbox cannot be opened.
     */
    public function poll(int $limit = 50, bool $queue = true): array
    {
        if (!self::imapAvailable()) {
            throw new RuntimeException(Craft::t('pigeon', 'PHP’s imap extension is not installed, so Pigeon cannot read a mailbox. Point your mail provider’s inbound webhook at Pigeon instead, pipe mail to php craft pigeon/inbound/import -, or install the extension (PECL “imap” on PHP 8.4+).'));
        }

        $settings = $this->settings();
        $host = $settings->env('imapHost');

        if ($host === '' || $settings->env('imapUsername') === '') {
            throw new RuntimeException(Craft::t('pigeon', 'No IMAP mailbox is configured. Set the host, username and password in Pigeon’s settings, under Reply by email.'));
        }

        $flags = match ($settings->imapEncryption) {
            Settings::IMAP_SSL => '/imap/ssl',
            Settings::IMAP_TLS => '/imap/tls',
            default => '/imap/notls',
        };
        $mailbox = sprintf('{%s:%d%s}%s', $host, $settings->imapPort, $flags, $settings->imapMailbox ?: 'INBOX');

        $stream = @imap_open($mailbox, $settings->env('imapUsername'), $settings->env('imapPassword'), 0, 1);

        if ($stream === false) {
            $error = (string)imap_last_error();
            imap_errors();

            throw new RuntimeException(Craft::t('pigeon', 'Could not open the mailbox: {error}', ['error' => $error]));
        }

        $counts = ['fetched' => 0, 'accepted' => 0, 'duplicates' => 0, 'rejected' => 0];
        $uids = imap_search($stream, 'UNSEEN', SE_UID) ?: [];

        try {
            foreach (array_slice($uids, 0, max(1, $limit)) as $uid) {
                $raw = (string)imap_fetchheader($stream, (int)$uid, FT_UID) . (string)imap_body($stream, (int)$uid, FT_UID | FT_PEEK);
                $counts['fetched']++;

                $result = $this->importRaw($raw, 'imap', $queue)['result'];
                $key = match ($result) {
                    self::RESULT_ACCEPTED => 'accepted',
                    self::RESULT_DUPLICATE => 'duplicates',
                    default => 'rejected',
                };
                $counts[$key]++;

                if ($settings->imapAfterImport === Settings::IMAP_DELETE) {
                    imap_delete($stream, (string)$uid, FT_UID);
                } else {
                    imap_setflag_full($stream, (string)$uid, '\\Seen', ST_UID);
                }
            }

            if ($settings->imapAfterImport === Settings::IMAP_DELETE) {
                imap_expunge($stream);
            }
        } finally {
            imap_close($stream);
        }

        return $counts;
    }

    // ------------------------------------------------------------------------- housekeeping

    /**
     * Put failed emails back in the queue, and any a crashed worker left half-done for more than
     * an hour.
     *
     * @return int How many.
     */
    public function retryFailed(): int
    {
        $ids = (new Query())
            ->select(['id'])
            ->from([self::TABLE_INBOUND])
            ->where(['or',
                ['status' => self::STATUS_FAILED],
                ['and', ['status' => 'processing'], ['<', 'dateUpdated', Db::prepareDateForDb(new DateTime('-1 hour'))]],
            ])
            ->andWhere(['not', ['payload' => null]])
            ->column();

        foreach ($ids as $id) {
            Db::update(self::TABLE_INBOUND, ['status' => self::STATUS_QUEUED], ['id' => (int)$id]);
            Queue::push(new ProcessInboundEmail(['inboundId' => (int)$id]));
        }

        return count($ids);
    }

    /** Process whatever is still queued, here and now — for a site with no queue runner. */
    public function processQueued(int $limit = 100): int
    {
        $ids = (new Query())
            ->select(['id'])
            ->from([self::TABLE_INBOUND])
            ->where(['status' => self::STATUS_QUEUED])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->column();

        foreach ($ids as $id) {
            $this->process((int)$id);
        }

        return count($ids);
    }

    /** @return list<array<string, mixed>> The latest rows, newest first, for the console and settings. */
    public function recent(int $limit = 20): array
    {
        return array_values((new Query())
            ->select(['id', 'provider', 'fromEmail', 'subject', 'status', 'reason', 'threadId', 'dateCreated'])
            ->from([self::TABLE_INBOUND])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->all());
    }

    /** Drop the record of emails older than `$days`. */
    public function prune(int $days = 90): int
    {
        return Db::delete(self::TABLE_INBOUND, [
            'and',
            ['not', ['status' => [self::STATUS_QUEUED, 'processing']]],
            ['<', 'dateCreated', Db::prepareDateForDb(new DateTime("-{$days} days"))],
        ]);
    }

    /** Shared by `Install` and the migration that adds the tables to an existing install. */
    public static function createTables(Migration $migration): void
    {
        $migration->createTable(self::TABLE_INBOUND, [
            'id' => $migration->primaryKey(),
            'provider' => $migration->string(16)->notNull(),
            // SHA-256 of the Message-ID, or of the content when there is none: the dedupe key.
            'messageHash' => $migration->char(64)->notNull(),
            'messageId' => $migration->string(),
            'fromEmail' => $migration->string()->notNull(),
            'subject' => $migration->string(),
            'status' => $migration->string(16)->notNull(),
            'reason' => $migration->string(),
            // The parsed message while it waits for the job; emptied once it has been handled.
            'payload' => $migration->mediumText(),
            'threadId' => $migration->integer(),
            'postedMessageId' => $migration->integer(),
            'attempts' => $migration->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'dateProcessed' => $migration->dateTime(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, self::TABLE_INBOUND, ['messageHash'], true);
        $migration->createIndex(null, self::TABLE_INBOUND, ['fromEmail', 'dateCreated'], false);
        $migration->createIndex(null, self::TABLE_INBOUND, ['status'], false);
        $migration->addForeignKey(null, self::TABLE_INBOUND, ['threadId'], '{{%pigeon_threads}}', ['id'], 'SET NULL', null);
        $migration->addForeignKey(null, self::TABLE_INBOUND, ['postedMessageId'], '{{%pigeon_messages}}', ['id'], 'SET NULL', null);

        $migration->createTable(self::TABLE_EMAIL_THREADS, [
            'id' => $migration->primaryKey(),
            'threadId' => $migration->integer()->notNull(),
            // Who the email was sent to (`out`) or who sent it (`in`); null for staff alerts,
            // which go to addresses rather than participants.
            'participantId' => $migration->integer(),
            'messageHash' => $migration->char(64)->notNull(),
            'messageId' => $migration->string(),
            'direction' => $migration->string(3)->notNull(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, self::TABLE_EMAIL_THREADS, ['messageHash'], true);
        $migration->createIndex(null, self::TABLE_EMAIL_THREADS, ['threadId', 'participantId'], false);
        $migration->addForeignKey(null, self::TABLE_EMAIL_THREADS, ['threadId'], '{{%pigeon_threads}}', ['id'], 'CASCADE', null);
        $migration->addForeignKey(null, self::TABLE_EMAIL_THREADS, ['participantId'], '{{%pigeon_participants}}', ['id'], 'CASCADE', null);
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
