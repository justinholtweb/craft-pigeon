<?php
/**
 * Pigeon reply by email — the parsers, the three providers' verification, and an email becoming a
 * reply on its conversation, posted only by someone who could have posted it on the site.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pigeon/tests/integration/inbound.php
 *
 * No mail provider is reachable from the harness, so every delivery is a signed fixture from
 * `_inbound-fixtures.php`, verified by the same provider classes a real one goes through. Settings
 * change in memory only. Nothing is mailed: the mailer is intercepted and every message kept for
 * inspection instead. Self-cleaning — every user, thread, asset, row and queued job the run makes
 * is removed at the end.
 */

$root = '/var/www/html';
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

require __DIR__ . '/_inbound-fixtures.php';

use craft\db\Query;
use craft\elements\Asset;
use craft\elements\User;
use craft\mail\Mailer;
use craft\mail\Message;
use craft\web\View;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\events\InboundEmailEvent;
use justinholtweb\pigeon\jobs\SendMessageNotification;
use justinholtweb\pigeon\mail\Addresses;
use justinholtweb\pigeon\mail\InboundMessage;
use justinholtweb\pigeon\mail\InboundRequest;
use justinholtweb\pigeon\mail\LoopGuard;
use justinholtweb\pigeon\mail\MimeParser;
use justinholtweb\pigeon\mail\MultipartParser;
use justinholtweb\pigeon\mail\providers\Mailgun;
use justinholtweb\pigeon\mail\providers\Postmark;
use justinholtweb\pigeon\mail\providers\SendGrid;
use justinholtweb\pigeon\mail\ReplyParser;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\records\AttachmentRecord;
use justinholtweb\pigeon\records\MessageRecord;
use justinholtweb\pigeon\records\ParticipantRecord;
use justinholtweb\pigeon\services\Inbound;
use yii\base\Event;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

Craft::$app->getPlugins()->loadPlugins();

// craft-penny's broken beforeSaveElement handler (see security.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$inbound = $plugin->inbound;
$run = bin2hex(random_bytes(3));
$domain = "pigeon-in-$run.test";
$fixtures = dirname(__DIR__) . '/fixtures/inbound';
$tempDir = Craft::$app->getPath()->getTempPath() . '/pigeon-inbound-test-' . $run;
$volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
$cleanup = ['users' => [], 'threads' => []];

// Reply by email on, in memory only.
$settings = $plugin->getSettings();
$settings->inboundEnabled = true;
$settings->inboundAddress = FIXTURE_DESK;
$settings->inboundRateLimit = 0;
$settings->inboundReplayWindow = 300;
$settings->inboundIgnoreAddresses = '';
$settings->postmarkUsername = FIXTURE_POSTMARK_USER;
$settings->postmarkPassword = FIXTURE_POSTMARK_PASSWORD;
$settings->mailgunSigningKey = FIXTURE_MAILGUN_KEY;
$settings->sendgridPublicKey = '';
$settings->sendgridUsername = '';
$settings->sendgridPassword = '';
$settings->inboundAttachments = true;
$settings->attachmentVolumeUid = (string)$volume?->uid;
$settings->allowedAttachmentExtensions = ['png', 'jpg', 'pdf', 'txt'];
$settings->maxAttachmentsPerMessage = 3;
$settings->maxAttachmentSizeMb = 2;
$settings->fromEmail = '';

// Every message the mailer is asked to send, kept and not sent.
$sent = [];
Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, static function(MailEvent $event) use (&$sent) {
    $sent[] = $event->message;
    $event->isValid = false;
});

$queueFloor = (int)(new Query())->from('{{%queue}}')->max('id');
$inboundFloor = (int)(new Query())->from([Inbound::TABLE_INBOUND])->max('id');

$user = static function(string $name, array $permissions) use (&$cleanup, $domain): User {
    $user = new User(['username' => "$name-" . substr($domain, 10, 6), 'email' => "$name@$domain", 'newPassword' => 'Pigeon-' . bin2hex(random_bytes(6))]);
    Craft::$app->getElements()->saveElement($user, false) or throw new RuntimeException(json_encode($user->getErrors()));
    Craft::$app->getUsers()->activateUser($user);
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
    $cleanup['users'][] = $user;

    return Craft::$app->getUsers()->getUserById($user->id);
};

/** Receive through the webhook path and process the queued row straight away. */
$deliver = static function(string $provider, InboundRequest $request, ?int $now = null) use ($inbound): array {
    $result = $inbound->receive($provider, $request, $now);

    if ($result['status'] === 200 && $result['result'] === Inbound::RESULT_ACCEPTED && $result['id'] !== null) {
        $inbound->process($result['id']);
    }

    $row = $result['id'] !== null ? (new Query())->from([Inbound::TABLE_INBOUND])->where(['id' => $result['id']])->one() : null;

    return $result + ['row' => $row ?: null];
};

/** A Mailgun reply to a reply address, from someone. */
$reply = static function(string $token, string $from, string $text, array $extra = []): InboundRequest {
    return mailgunRequest(array_merge([
        'recipient' => 'messages+' . $token . '@pigeon.example.com',
        'To' => 'messages+' . $token . '@pigeon.example.com',
        'from' => $from,
        'sender' => $from,
        'subject' => 'Re: a conversation',
        'body-plain' => $text,
    ], $extra));
};

$latest = static fn(int $threadId): ?MessageRecord => MessageRecord::find()->where(['threadId' => $threadId, 'isInternalNote' => false])->orderBy(['id' => SORT_DESC])->one();
$thread = static fn(int $id): Thread => Thread::find()->id($id)->status(null)->one();
$queuedFor = static fn(int $messageId): int => (int)(new Query())->from('{{%queue}}')
    ->where(['>', 'id', $queueFloor])
    ->andWhere(['like', 'job', '"messageId";i:' . $messageId . ';'])
    ->count();

try {
    $staff = $user('staff', ['accesscp', 'accessplugin-pigeon', 'pigeon:accessplugin', 'pigeon:managethreads']);
    $staffDirect = $user('staffdirect', ['accesscp', 'accessplugin-pigeon', 'pigeon:accessplugin', 'pigeon:viewdirectthreads', 'pigeon:managethreads']);
    $inboxOnly = $user('inboxonly', ['accesscp', 'accessplugin-pigeon', 'pigeon:accessplugin']);
    $alice = $user('alice', []);
    $bob = $user('bob', []);
    $carol = $user('carol', []);

    $guestEmail = "gina@$domain";
    $support = $plugin->threads->createSupportThread("Help $run", $guestEmail, 'Gina Guest');
    $cleanup['threads'][] = $support->id;
    $plugin->messages->post($support, ['body' => 'My first question', 'authorEmail' => $guestEmail, 'authorName' => 'Gina Guest']);
    $plugin->threads->assign($support, $staff->id);
    $guest = ParticipantRecord::findOne(['threadId' => $support->id, 'userId' => null]);
    $plugin->participants->mintToken($guest);
    $staffP = $plugin->participants->getForUser($support->id, $staff->id);

    $direct = $plugin->threads->createDirectThread("Private $run", $alice->id, [$bob->id, $carol->id]);
    $cleanup['threads'][] = $direct->id;
    $plugin->messages->post($direct, ['body' => 'Hi Bob', 'authorUserId' => $alice->id, 'authorName' => 'Alice']);
    $aliceP = $plugin->participants->getForUser($direct->id, $alice->id);
    $bobP = $plugin->participants->getForUser($direct->id, $bob->id);
    $carolP = $plugin->participants->getForUser($direct->id, $carol->id);

    // ------------------------------------------------------------------------------------------
    section('Addresses and tokens');

    check('a reply address is the mailbox with a tag, one tag only', function() {
        return Addresses::replyAddress('Messages+old@Pigeon.Example.com', str_repeat('a', 32)) === 'messages+' . str_repeat('a', 32) . '@pigeon.example.com'
            ?: 'got ' . Addresses::replyAddress('Messages+old@Pigeon.Example.com', str_repeat('a', 32));
    });

    check('the token is read only from the reply mailbox, lower-cased', function() {
        $token = 'k3v9' . str_repeat('x', 28);

        return Addresses::tokenFrom('MESSAGES+' . strtoupper($token) . '@pigeon.example.com', FIXTURE_DESK) === $token
            && Addresses::tokenFrom('messages+' . $token . '@other.example.com', FIXTURE_DESK) === null
            && Addresses::tokenFrom('sales+' . $token . '@pigeon.example.com', FIXTURE_DESK) === null
            ?: 'mismatch';
    });

    check('every participant gets a 32-character lower-case reply token of its own', function() use ($guest, $aliceP, $bobP, $staffP) {
        $tokens = [$guest->replyToken, $aliceP->replyToken, $bobP->replyToken, $staffP->replyToken];

        return count(array_filter($tokens, static fn($t) => Addresses::isToken((string)$t))) === 4 && count(array_unique($tokens)) === 4
            && $guest->replyToken !== $guest->tokenHash
            ?: json_encode($tokens);
    });

    check('a reply token is looked up exactly: no patterns, no prefixes, case folded once', function() use ($plugin, $guest) {
        $p = $plugin->participants;

        return $p->findByReplyToken($guest->replyToken)?->id === $guest->id
            && $p->findByReplyToken(strtoupper($guest->replyToken))?->id === $guest->id
            && $p->findByReplyToken(substr($guest->replyToken, 0, 31) . '%') === null
            && $p->findByReplyToken('*') === null
            && $p->findByReplyToken(substr($guest->replyToken, 0, 16)) === null
            ?: 'loose match';
    });

    // ------------------------------------------------------------------------------------------
    section('Quoted replies, signatures and HTML');

    check('Gmail’s wrapped “On … wrote:” and the quote under it are cut', function() {
        $text = "Still broken.\n\nOn Thu, 8 Oct 2026 at 17:02, Messages <messages+abc@pigeon.example.com>\nwrote:\n> We fixed it.\n>\n> The team";

        return ReplyParser::strip($text) === 'Still broken.' ?: json_encode(ReplyParser::strip($text));
    });

    check('Outlook’s header block and signatures go; inline answers stay', function() {
        $outlook = "Here you go.\n\n________________________________\nFrom: Messages <messages@pigeon.example.com>\nSent: Thursday, October 8, 2026 5:02 PM\nTo: Pat\nSubject: Re: Help";
        $inline = "> Which browser?\nFirefox 140.\n\n> old stuff\n> more";

        return ReplyParser::strip($outlook) === 'Here you go.'
            && ReplyParser::strip("Thanks!\n\n-- \nPat\nAcme") === 'Thanks!'
            && ReplyParser::strip("Done.\n\nSent from my iPhone") === 'Done.'
            && ReplyParser::strip($inline) === "> Which browser?\nFirefox 140."
            ?: 'wrong';
    });

    check('HTML becomes text: script/style/quotes dropped, links keep their address', function() {
        $html = '<html><head><style>p{}</style><script>alert(1)</script></head><body><p>Hi &amp; hello</p>'
            . '<p>See <a href="https://x.example/y">this page</a></p><div class="gmail_quote">On … wrote:<blockquote>old</blockquote></div>'
            . '<img src=x onerror=alert(2)></body></html>';

        return ReplyParser::htmlToText($html) === "Hi & hello\n\nSee this page (https://x.example/y)" ?: json_encode(ReplyParser::htmlToText($html));
    });

    // ------------------------------------------------------------------------------------------
    section('MIME and multipart');

    $store = static fn(string $bytes): string => (new InboundRequest(tempDir: $tempDir))->writeTemp($bytes);

    check('encoded words, quoted-printable, base64 and an RFC 2231 file name', function() use ($fixtures, $store) {
        $raw = strtr((string)file_get_contents("$fixtures/gmail-reply.eml"), [
            '{{TOKEN}}' => str_repeat('q', 32), '{{FROM}}' => 'm@example.org', '{{FROM_UPPER}}' => 'M@Example.org', '{{MSGID}}' => 'mime-1@example.org',
        ]);
        $m = MimeParser::parse($raw, $store);
        $a = $m->attachments[0] ?? null;

        return $m->fromEmail === 'm@example.org' && $m->fromName === 'María López' && $m->subject === 'Re: “Invoice wrong”'
            && str_contains($m->text, 'The total should be €40, not €400.') && $m->messageId === 'mime-1@example.org'
            && $m->inReplyTo === ['pigeon.outbound-001@pigeon.example.com']
            && $a !== null && $a->filename === 'résumé screen.png' && file_get_contents($a->path) === tinyPng()
            ?: json_encode([$m->fromName, $m->subject, $m->messageId, $a?->toArray()]);
    });

    check('multipart/form-data parsing is binary-safe', function() use ($store) {
        $binary = tinyPng() . "\r\n--not-a-boundary\r\n\x00\xff";
        [$body, $type] = sendgridBody(['from' => 'a@b.c', 'text' => "two\r\nlines"], ['attachment1' => ['filename' => 'x.png', 'type' => 'image/png', 'bytes' => $binary]]);
        [$fields, $files] = MultipartParser::parse($body, $type, $store);

        return $fields['text'] === "two\r\nlines" && file_get_contents($files['attachment1']['tmp_name']) === $binary ?: json_encode($fields);
    });

    // ------------------------------------------------------------------------------------------
    section('Loop protection');

    $loop = static function(array $headers, string $from = 'person@example.org'): ?string {
        $m = new InboundMessage();
        $m->fromEmail = $from;
        $m->subject = 'Hi';

        foreach ($headers as $name => $value) {
            $m->addHeader($name, $value);
        }

        return LoopGuard::reason($m, [FIXTURE_DESK, 'noreply@pigeon.example.com']);
    };

    check('Auto-Submitted (but not “no”), Precedence, X-Autoreply, List-Id and bounces are machines', function() use ($loop) {
        return $loop(['Auto-Submitted' => 'auto-generated']) === LoopGuard::AUTO_SUBMITTED
            && $loop(['Auto-Submitted' => 'no']) === null
            && $loop(['Precedence' => 'bulk']) === LoopGuard::PRECEDENCE
            && $loop(['X-Autoreply' => 'yes']) === LoopGuard::AUTO_REPLY
            && $loop(['List-Id' => '<team.example.org>']) === LoopGuard::MAILING_LIST
            && $loop([], 'MAILER-DAEMON@mx.example.org') === LoopGuard::BOUNCE
            && $loop([], 'messages+' . str_repeat('a', 32) . '@pigeon.example.com') === LoopGuard::OWN_ADDRESS
            ?: 'wrong';
    });

    // ------------------------------------------------------------------------------------------
    section('Postmark: basic auth');

    $postmark = new Postmark(FIXTURE_POSTMARK_USER, FIXTURE_POSTMARK_PASSWORD);

    check('the right credentials pass', fn() => $postmark->verify(postmarkRequest(postmarkPayload()), time()) === null ?: 'refused');
    check('a wrong password is refused', fn() => $postmark->verify(postmarkRequest(postmarkPayload(), FIXTURE_POSTMARK_USER, 'nope'), time()) === 'bad credentials' ?: 'accepted');
    check('a wrong username is refused', fn() => $postmark->verify(postmarkRequest(postmarkPayload(), 'admin', FIXTURE_POSTMARK_PASSWORD), time()) === 'bad credentials' ?: 'accepted');
    check('no credentials at all are refused', fn() => $postmark->verify(postmarkRequest(postmarkPayload(), null, null), time()) !== null ?: 'accepted');
    check('unconfigured refuses everything, even empty credentials', function() {
        $empty = new Postmark('', '');

        return !$empty->isConfigured() && $empty->verify(postmarkRequest(postmarkPayload(), '', ''), time()) === 'not configured' ?: 'accepted';
    });

    // ------------------------------------------------------------------------------------------
    section('Mailgun: HMAC, timestamp, single-use token');

    $seen = [];
    $mailgun = new Mailgun(FIXTURE_MAILGUN_KEY, 300, static function(string $token) use (&$seen): bool {
        if (isset($seen[$token])) {
            return false;
        }

        return $seen[$token] = true;
    });

    check('a correctly signed post passes', fn() => $mailgun->verify(mailgunRequest(), time()) === null ?: 'refused');
    check('signed with the wrong key is refused', fn() => $mailgun->verify(mailgunRequest(key: 'other-key'), time()) === 'bad signature' ?: 'accepted');
    check('a tampered timestamp breaks the signature', function() use ($mailgun) {
        $request = mailgunRequest();
        $request->post['timestamp'] = (string)((int)$request->post['timestamp'] + 1);

        return $mailgun->verify($request, time()) === 'bad signature' ?: 'accepted';
    });
    check('a correctly signed but stale post is refused (300 s replay window)', fn() => $mailgun->verify(mailgunRequest(timestamp: time() - 301), time()) === 'stale timestamp' ?: 'accepted');
    check('the same token twice is refused the second time', function() use ($mailgun) {
        $token = bin2hex(random_bytes(25));
        $first = $mailgun->verify(mailgunRequest(token: $token), time());
        $second = $mailgun->verify(mailgunRequest(token: $token), time());

        return $first === null && $second === 'replayed token' ?: json_encode([$first, $second]);
    });
    check('a forged signature does not burn a real token', function() use ($mailgun) {
        $token = bin2hex(random_bytes(25));
        $forged = $mailgun->verify(mailgunRequest(token: $token, key: 'wrong'), time());
        $real = $mailgun->verify(mailgunRequest(token: $token), time());

        return $forged === 'bad signature' && $real === null ?: json_encode([$forged, $real]);
    });
    check('unsigned is refused', function() use ($mailgun) {
        $request = mailgunRequest();
        unset($request->post['signature']);

        return $mailgun->verify($request, time()) === 'unsigned' ?: 'accepted';
    });
    check('through the service, a replayed Mailgun token is refused (the nonce lives in the cache)', function() use ($inbound) {
        $token = bin2hex(random_bytes(25));
        $first = $inbound->receive('mailgun', mailgunRequest(['from' => 'nonce@example.org', 'sender' => 'nonce@example.org'], token: $token));
        $second = $inbound->receive('mailgun', mailgunRequest(['from' => 'nonce@example.org', 'sender' => 'nonce@example.org'], token: $token));

        return $first['status'] === 200 && $second['status'] === 401 ?: json_encode([$first, $second]);
    });

    // ------------------------------------------------------------------------------------------
    section('SendGrid: ECDSA over timestamp + raw body');

    [$sgPrivate, $sgPublic] = sendgridKeys();
    $sendgrid = new SendGrid(sendgridBareKey($sgPublic), '', '', 300);
    [$sgBody, $sgType] = sendgridBody([
        'from' => 'SG Person <sg@example.org>',
        'to' => FIXTURE_DESK,
        'subject' => 'From SendGrid',
        'text' => 'Parsed by SendGrid.',
        'headers' => 'Message-ID: <sg-' . bin2hex(random_bytes(6)) . "@example.org>\nFrom: SG Person <sg@example.org>",
        'envelope' => json_encode(['to' => [FIXTURE_DESK], 'from' => 'sg@example.org']),
        'attachments' => '0',
    ]);

    check('a signed raw body passes, with the key as bare base64 or as PEM', function() use ($sendgrid, $sgBody, $sgType, $sgPrivate, $sgPublic) {
        return $sendgrid->verify(sendgridRequest($sgBody, $sgType, $sgPrivate), time()) === null
            && (new SendGrid($sgPublic, '', '', 300))->verify(sendgridRequest($sgBody, $sgType, $sgPrivate), time()) === null
            ?: 'refused';
    });
    check('one changed byte in the body is refused', function() use ($sendgrid, $sgBody, $sgType, $sgPrivate) {
        $request = sendgridRequest($sgBody, $sgType, $sgPrivate);
        $request->rawBody = str_replace('Parsed by', 'Parsed bY', $request->rawBody);

        return $sendgrid->verify($request, time()) === 'bad signature' ?: 'accepted';
    });
    check('a different key’s signature is refused', function() use ($sendgrid, $sgBody, $sgType) {
        [$otherPrivate] = sendgridKeys();

        return $sendgrid->verify(sendgridRequest($sgBody, $sgType, $otherPrivate), time()) === 'bad signature' ?: 'accepted';
    });
    check('a correctly signed but stale post is refused', fn() => $sendgrid->verify(sendgridRequest($sgBody, $sgType, $sgPrivate, time() - 3600), time()) === 'stale timestamp' ?: 'accepted');
    check('no raw body (PHP parsed the multipart: enable_post_data_reading is on) is refused, saying why', function() use ($sendgrid, $sgBody, $sgType, $sgPrivate) {
        $request = sendgridRequest($sgBody, $sgType, $sgPrivate);
        $request->rawBody = '';
        $request->post = ['from' => 'x@y.z'];

        return str_starts_with((string)$sendgrid->verify($request, time()), 'raw body unavailable') ?: 'accepted';
    });
    check('basic-auth fallback when no key is set', function() use ($sgBody, $sgType) {
        $basic = new SendGrid('', 'sg-user', 'sg-pass', 300);
        $ok = new InboundRequest(['content-type' => $sgType], $sgBody, [], [], 'sg-user', 'sg-pass');
        $bad = new InboundRequest(['content-type' => $sgType], $sgBody, [], [], 'sg-user', 'nope');

        return $basic->verify($ok, time()) === null && $basic->verify($bad, time()) === 'bad credentials' ?: 'wrong';
    });

    // ------------------------------------------------------------------------------------------
    section('The webhook path: refusals');

    check('an unknown provider is a 404', fn() => $inbound->receive('sparkpost', postmarkRequest(postmarkPayload()))['status'] === 404 ?: 'not 404');

    check('a provider with no secret configured is a 403, and nothing is stored', function() use ($inbound, $settings) {
        $settings->postmarkPassword = '';
        $before = (int)(new Query())->from([Inbound::TABLE_INBOUND])->count();

        try {
            $result = $inbound->receive('postmark', postmarkRequest(postmarkPayload(), FIXTURE_POSTMARK_USER, ''));
        } finally {
            $settings->postmarkPassword = FIXTURE_POSTMARK_PASSWORD;
        }

        return $result['status'] === 403 && (int)(new Query())->from([Inbound::TABLE_INBOUND])->count() === $before ?: json_encode($result);
    });

    check('a secret naming an unset environment variable counts as no secret', function() use ($inbound, $settings) {
        $settings->postmarkPassword = '$PIGEON_TEST_UNSET_' . strtoupper(bin2hex(random_bytes(3)));

        try {
            $result = $inbound->receive('postmark', postmarkRequest(postmarkPayload(), FIXTURE_POSTMARK_USER, $settings->postmarkPassword));
        } finally {
            $settings->postmarkPassword = FIXTURE_POSTMARK_PASSWORD;
        }

        return $result['status'] === 403 ?: json_encode($result);
    });

    check('bad credentials are a 401, and nothing is stored', function() use ($inbound) {
        $before = (int)(new Query())->from([Inbound::TABLE_INBOUND])->count();
        $result = $inbound->receive('postmark', postmarkRequest(postmarkPayload(), FIXTURE_POSTMARK_USER, 'wrong'));

        return $result['status'] === 401 && (int)(new Query())->from([Inbound::TABLE_INBOUND])->count() === $before ?: json_encode($result);
    });

    check('accepted mail is answered 200 and queued, not processed in the request', function() use ($inbound, $queueFloor, $guest, $guestEmail) {
        $result = $inbound->receive('postmark', postmarkRequest(postmarkPayload([
            'From' => $guestEmail, 'FromFull' => ['Email' => $guestEmail, 'Name' => 'Gina'],
            'To' => 'messages+' . $guest->replyToken . '@pigeon.example.com',
            'ToFull' => [['Email' => 'messages+' . $guest->replyToken . '@pigeon.example.com']],
            'TextBody' => 'Queued, not posted yet.',
        ])));
        $row = (new Query())->from([Inbound::TABLE_INBOUND])->where(['id' => $result['id']])->one();
        $job = (new Query())->from('{{%queue}}')->where(['>', 'id', $queueFloor])->andWhere(['like', 'job', 'ProcessInboundEmail'])->exists();
        $posted = MessageRecord::find()->where(['body' => 'Queued, not posted yet.'])->exists();

        // Processed now so the rest of the run starts clean.
        $inbound->process((int)$result['id']);

        return $result['status'] === 200 && $row['status'] === Inbound::STATUS_QUEUED && $job && !$posted ?: json_encode([$result, $row['status'] ?? null, $job, $posted]);
    });

    // ------------------------------------------------------------------------------------------
    section('Email going out carries the reply address');

    $sendJob = static function(array $config) use (&$sent): ?Message {
        $sent = [];
        (new SendMessageNotification($config))->execute(null);

        return $sent[0] ?? null;
    };
    $firstMessage = $latest($support->id);

    check('a guest’s notification: Reply-To messages+<their token>@, a recorded Message-ID, machine-sent', function() use ($sendJob, $firstMessage, $guest, $support) {
        $mail = $sendJob(['messageId' => $firstMessage->id, 'participantId' => $guest->id]);
        $headers = $mail?->getSymfonyEmail()->getHeaders();
        $replyTo = array_keys((array)$mail?->getReplyTo())[0] ?? '';
        $messageId = trim((string)$headers?->get('Message-ID')?->getBodyAsString(), '<>');
        $row = (new Query())->from([Inbound::TABLE_EMAIL_THREADS])->where(['messageHash' => Addresses::messageHash($messageId)])->one();

        return $replyTo === 'messages+' . $guest->replyToken . '@pigeon.example.com'
            && str_starts_with($messageId, 'pigeon.') && $row && (int)$row['threadId'] === (int)$support->id
            && (int)$row['participantId'] === (int)$guest->id && $row['direction'] === 'out'
            && $headers->get('Auto-Submitted')?->getBodyAsString() === 'auto-generated'
            && str_contains((string)$mail->getSymfonyEmail()->getTextBody(), 'Or reply to this email')
            ?: json_encode([$replyTo, $messageId, $row]);
    });

    check('the next email to the same participant threads under the first (In-Reply-To)', function() use ($sendJob, $firstMessage, $guest, $support) {
        $first = (new Query())->select(['messageId'])->from([Inbound::TABLE_EMAIL_THREADS])->where(['threadId' => $support->id, 'participantId' => $guest->id])->orderBy(['id' => SORT_ASC])->scalar();
        $mail = $sendJob(['messageId' => $firstMessage->id, 'participantId' => $guest->id]);

        return str_contains((string)$mail?->getSymfonyEmail()->getHeaders()->get('In-Reply-To')?->getBodyAsString(), (string)$first) ?: 'not threaded';
    });

    check('a staff alert (to an address, not a participant) gets the plain mailbox and an unowned Message-ID', function() use ($sendJob, $firstMessage) {
        $mail = $sendJob(['messageId' => $firstMessage->id, 'adhocEmail' => 'alerts@example.org', 'staffAlert' => true]);
        $replyTo = array_keys((array)$mail?->getReplyTo())[0] ?? '';
        $messageId = trim((string)$mail?->getSymfonyEmail()->getHeaders()->get('Message-ID')?->getBodyAsString(), '<>');
        $row = (new Query())->from([Inbound::TABLE_EMAIL_THREADS])->where(['messageHash' => Addresses::messageHash($messageId)])->one();

        return $replyTo === FIXTURE_DESK && $row && $row['participantId'] === null ?: json_encode([$replyTo, $row]);
    });

    check('the guest-link email is an automatic answer (Auto-Submitted: auto-replied)', function() use ($inbound, $support, $guest) {
        $mail = new Message();
        $mail->setTo('x@example.org');
        $inbound->prepareOutgoing($mail, $support, $guest, true);

        return $mail->getSymfonyEmail()->getHeaders()->get('Auto-Submitted')?->getBodyAsString() === 'auto-replied' ?: 'missing';
    });

    check('with reply by email off, nothing is added to outgoing mail and no hint is shown', function() use ($sendJob, $firstMessage, $guest, $settings) {
        $settings->inboundEnabled = false;

        try {
            $mail = $sendJob(['messageId' => $firstMessage->id, 'participantId' => $guest->id]);
        } finally {
            $settings->inboundEnabled = true;
        }

        $headers = $mail?->getSymfonyEmail()->getHeaders();

        return $mail !== null && in_array($mail->getReplyTo(), [null, [], ''], true) && !$headers->has('Auto-Submitted')
            && !str_contains((string)$headers->get('Message-ID')?->getBodyAsString(), 'pigeon.')
            && !str_contains((string)$mail->getSymfonyEmail()->getTextBody(), 'Or reply to this email')
            ?: json_encode($mail?->getReplyTo());
    });

    // ------------------------------------------------------------------------------------------
    section('A guest’s reply lands on their conversation');

    $plugin->threads->setStatus($thread($support->id), ThreadStatus::Open);
    $guestReply = $deliver('mailgun', $reply($guest->replyToken, "Gina <$guestEmail>", "It turned up this morning, thanks!\n\nOn Fri, 9 Oct 2026, Messages <messages@pigeon.example.com> wrote:\n> Sorry to hear that."));
    $posted = $latest($support->id);

    check('posted as the guest, quote stripped, matched by the reply address', function() use ($guestReply, $posted, $support, $guestEmail) {
        return $guestReply['row']['status'] === Inbound::STATUS_REPLY && (int)$guestReply['row']['threadId'] === (int)$support->id
            && (int)$guestReply['row']['postedMessageId'] === (int)$posted->id
            && $posted->authorUserId === null && $posted->authorEmail === $guestEmail && $posted->authorName === 'Gina Guest'
            && $posted->body === 'It turned up this morning, thanks!' && !$posted->isInternalNote
            && str_contains((string)$guestReply['row']['reason'], 'reply address')
            ?: json_encode([$guestReply['row'], $posted?->toArray()]);
    });

    check('it does what a guest’s web reply does: the support thread goes back to pending', fn() => $thread($support->id)->threadStatus === ThreadStatus::Pending->value ?: $thread($support->id)->threadStatus);

    check('…and notifies the other participants (staff), not the guest', function() use ($queuedFor, $posted) {
        return $queuedFor((int)$posted->id) === 1 ?: 'queued ' . $queuedFor((int)$posted->id);
    });

    check('the payload is emptied once handled; the Message-ID is remembered for threading', function() use ($guestReply) {
        $known = (new Query())->from([Inbound::TABLE_EMAIL_THREADS])->where(['messageHash' => Addresses::messageHash((string)$guestReply['row']['messageId']), 'direction' => 'in'])->exists();

        return $guestReply['row']['payload'] === null && $known ?: 'payload kept or id forgotten';
    });

    check('the same Message-ID delivered again is a duplicate, not a second message', function() use ($deliver, $reply, $guest, $guestEmail, $guestReply, $support) {
        $before = (int)MessageRecord::find()->where(['threadId' => $support->id])->count();
        $again = $deliver('mailgun', $reply($guest->replyToken, $guestEmail, 'It turned up this morning, thanks!', ['_messageId' => $guestReply['row']['messageId']]));

        return $again['result'] === Inbound::RESULT_DUPLICATE && (int)MessageRecord::find()->where(['threadId' => $support->id])->count() === $before
            ?: json_encode([$again['result']]);
    });

    check('the text is stored as text and escaped when shown — never rendered', function() use ($deliver, $reply, $guest, $guestEmail, $latest, $support) {
        $deliver('mailgun', $reply($guest->replyToken, $guestEmail, '<script>alert(1)</script><img src=x onerror=alert(2)> plain'));
        $message = $latest($support->id);
        $html = Craft::$app->getView()->renderTemplate('pigeon/_front/_messages', ['messages' => [$message], 'currentUserId' => null], View::TEMPLATE_MODE_CP);

        return str_contains($message->body, '<script>') && !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;') && !str_contains($html, '<img')
            ?: $html;
    });

    check('an HTML-only reply is turned into text without the quote', function() use ($deliver, $reply, $guest, $guestEmail, $latest, $support) {
        $deliver('mailgun', $reply($guest->replyToken, $guestEmail, '', [
            'body-html' => '<p>New <b>line</b></p><script>alert(1)</script><div class="gmail_quote">On … wrote:<blockquote>old</blockquote></div>',
        ]));
        $body = $latest($support->id)->body;

        return $body === 'New line' ?: json_encode($body);
    });

    check('a reply that is only quote (nothing new) is ignored', function() use ($deliver, $reply, $guest, $guestEmail) {
        $result = $deliver('mailgun', $reply($guest->replyToken, $guestEmail, "> Everything quoted\n> and nothing new"));

        return $result['row']['status'] === Inbound::STATUS_IGNORED ?: json_encode($result['row']);
    });

    check('a raw Gmail reply (import): encoded From matched case-insensitively, attachment kept', function() use ($inbound, $fixtures, $guest, $guestEmail, $support, $latest) {
        $raw = strtr((string)file_get_contents("$fixtures/gmail-reply.eml"), [
            '{{TOKEN}}' => $guest->replyToken, '{{FROM}}' => $guestEmail, '{{FROM_UPPER}}' => strtoupper($guestEmail), '{{MSGID}}' => 'gmail-' . bin2hex(random_bytes(6)) . '@mail.gmail.com',
        ]);
        $result = $inbound->importRaw($raw, 'import', false);
        $row = (new Query())->from([Inbound::TABLE_INBOUND])->where(['id' => $result['id']])->one();
        $message = $latest($support->id);
        $files = AttachmentRecord::find()->where(['messageId' => $message->id])->all();

        return $row['status'] === Inbound::STATUS_REPLY && $message->body === "Thanks — the invoice is still wrong after the fix.\nThe total should be €40, not €400."
            // Craft's own asset-name sanitising decides the stored name; the file is the point.
            && count($files) === 1 && str_ends_with((string)$files[0]->filename, '.png') && str_contains((string)$files[0]->filename, 'sum')
            ?: json_encode([$row, $message->body, array_map(static fn($f) => $f->filename, $files)]);
    });

    check('a reply carrying only In-Reply-To (tag lost) still finds the conversation and its participant', function() use ($deliver, $support, $guest, $guestEmail, $sgPrivate, $sgPublic, $settings, $tempDir, $latest) {
        $settings->sendgridPublicKey = sendgridBareKey($sgPublic);
        $outId = (new Query())->select(['messageId'])->from([Inbound::TABLE_EMAIL_THREADS])->where(['threadId' => $support->id, 'participantId' => $guest->id, 'direction' => 'out'])->scalar();

        [$body, $type] = sendgridBody([
            'from' => "Gina <$guestEmail>",
            'to' => FIXTURE_DESK,
            'subject' => 'Re: your message',
            'text' => "One more thing: the box was damaged.\n\n> quoted",
            'headers' => 'Message-ID: <sg-' . bin2hex(random_bytes(6)) . "@example.org>\nIn-Reply-To: <$outId>\nFrom: Gina <$guestEmail>",
            'attachments' => '0',
        ]);

        $result = $deliver('sendgrid', sendgridRequest($body, $type, $sgPrivate, null, $tempDir));

        return $result['row']['status'] === Inbound::STATUS_REPLY && (int)$result['row']['threadId'] === (int)$support->id
            && str_contains((string)$result['row']['reason'], 'headers') && $latest($support->id)->body === 'One more thing: the box was damaged.'
            ?: json_encode($result['row']);
    });

    check('mail that is not a reply to a conversation is refused — Pigeon doesn’t start threads by email', function() use ($deliver) {
        $result = $deliver('postmark', postmarkRequest(postmarkPayload(['Subject' => 'Brand new question'])));

        return $result['row']['status'] === Inbound::STATUS_REJECTED && $result['row']['threadId'] === null ?: json_encode($result['row']);
    });

    check('a well-formed token that belongs to nobody is refused the same way', function() use ($deliver, $reply) {
        $result = $deliver('mailgun', $reply(str_repeat('z', 32), 'guess@example.org', 'hello'));

        return $result['row']['status'] === Inbound::STATUS_REJECTED && $result['row']['threadId'] === null ?: json_encode($result['row']);
    });

    // ------------------------------------------------------------------------------------------
    section('Sender verification: only someone entitled to post');

    $count = static fn(int $threadId): int => (int)MessageRecord::find()->where(['threadId' => $threadId])->count();

    check('the guest’s reply address used by somebody else is refused, and nothing is posted', function() use ($deliver, $reply, $guest, $support, $count) {
        $before = $count($support->id);
        $result = $deliver('mailgun', $reply($guest->replyToken, 'Mallory <mallory@example.com>', 'Send the refund to my account instead.'));

        return $result['row']['status'] === Inbound::STATUS_REJECTED && $count($support->id) === $before
            && str_contains((string)$result['row']['reason'], 'somebody else')
            ?: json_encode($result['row']);
    });

    check('one participant’s From on another participant’s reply address is refused (Alice on Bob’s)', function() use ($deliver, $reply, $bobP, $alice, $direct, $count) {
        $before = $count($direct->id);
        $result = $deliver('mailgun', $reply($bobP->replyToken, $alice->email, 'Pretending to be Bob'));

        return $result['row']['status'] === Inbound::STATUS_REJECTED && $count($direct->id) === $before ?: json_encode($result['row']);
    });

    check('a reply-all carrying two participants’ tagged addresses is matched to the sender’s own', function() use ($deliver, $aliceP, $bobP, $bob, $direct, $latest) {
        $result = $deliver('mailgun', mailgunRequest([
            'recipient' => 'messages+' . $aliceP->replyToken . '@pigeon.example.com',
            'To' => 'messages+' . $aliceP->replyToken . '@pigeon.example.com',
            'Cc' => 'messages+' . $bobP->replyToken . '@pigeon.example.com',
            'from' => $bob->email,
            'sender' => $bob->email,
            'body-plain' => 'Reply-all from Bob',
        ]));

        return $result['row']['status'] === Inbound::STATUS_REPLY && (int)$latest($direct->id)->authorUserId === (int)$bob->id ?: json_encode($result['row']);
    });

    check('a user’s own reply posts as that user (authorUserId), into a user-to-user thread', function() use ($deliver, $reply, $aliceP, $alice, $direct, $latest, $queuedFor) {
        $result = $deliver('mailgun', $reply($aliceP->replyToken, strtoupper($alice->email), "Sure, Friday works.\n\n-- \nAlice"));
        $message = $latest($direct->id);

        return $result['row']['status'] === Inbound::STATUS_REPLY && (int)$message->authorUserId === (int)$alice->id
            && $message->body === 'Sure, Friday works.' && $queuedFor((int)$message->id) === 2
            ?: json_encode([$result['row'], $message?->toArray()]);
    });

    check('a user whose account is suspended can’t post by email (they couldn’t sign in to)', function() use ($deliver, $reply, $carolP, $carol, $direct, $count) {
        Craft::$app->getUsers()->suspendUser($carol);
        $before = $count($direct->id);

        try {
            $result = $deliver('mailgun', $reply($carolP->replyToken, $carol->email, 'Still here'));
        } finally {
            Craft::$app->getUsers()->unsuspendUser(Craft::$app->getUsers()->getUserById($carol->id));
        }

        return $result['row']['status'] === Inbound::STATUS_REJECTED && $count($direct->id) === $before ?: json_encode($result['row']);
    });

    check('a user who has left the conversation can’t post to it', function() use ($deliver, $reply, $carolP, $carol, $direct, $count) {
        $carolP->leftAt = craft\helpers\Db::prepareDateForDb(new DateTime());
        $carolP->save(false);
        $before = $count($direct->id);
        $result = $deliver('mailgun', $reply($carolP->replyToken, $carol->email, 'I left but here I am'));

        return $result['row']['status'] === Inbound::STATUS_REJECTED && $count($direct->id) === $before ?: json_encode($result['row']);
    });

    check('a guest whose link has lapsed can’t post until a new one is sent', function() use ($deliver, $reply, $guest, $guestEmail, $support, $count, $plugin) {
        $guest->tokenExpiresAt = craft\helpers\Db::prepareDateForDb(new DateTime('-1 day'));
        $guest->save(false);
        $before = $count($support->id);
        $expired = $deliver('mailgun', $reply($guest->replyToken, $guestEmail, 'Too late?'));

        $plugin->participants->mintToken($guest);
        $renewed = $deliver('mailgun', $reply($guest->replyToken, $guestEmail, 'Back with a fresh link'));

        return $expired['row']['status'] === Inbound::STATUS_REJECTED && str_contains((string)$expired['row']['reason'], 'expired')
            && $renewed['row']['status'] === Inbound::STATUS_REPLY && $count($support->id) === $before + 1
            ?: json_encode([$expired['row'], $renewed['row']]);
    });

    // A staff alert's Message-ID, as if a support address had been emailed about the thread.
    $alertId = static function(Thread $t) use ($inbound): string {
        $mail = new Message();
        $mail->setTo('alerts@example.org');
        $inbound->prepareOutgoing($mail, $t, null);

        return trim((string)$mail->getSymfonyEmail()->getHeaders()->get('Message-ID')?->getBodyAsString(), '<>');
    };
    $byHeader = static fn(string $from, string $inReplyTo, string $text): InboundRequest => mailgunRequest([
        'from' => $from, 'sender' => $from, 'body-plain' => $text, 'In-Reply-To' => "<$inReplyTo>",
    ]);

    check('staff answering a support alert post as themselves — and join the thread, as a CP reply does', function() use ($deliver, $byHeader, $alertId, $support, $staffDirect, $latest, $plugin) {
        $result = $deliver('mailgun', $byHeader($staffDirect->email, $alertId($thread = Thread::find()->id($support->id)->status(null)->one()), 'On it — sending a replacement.'));
        $message = $latest($support->id);

        return $result['row']['status'] === Inbound::STATUS_REPLY && (int)$message->authorUserId === (int)$staffDirect->id
            && $plugin->participants->getForUser($support->id, $staffDirect->id)?->role === 'admin'
            && Thread::find()->id($support->id)->status(null)->one()->threadStatus === ThreadStatus::Open->value
            ?: json_encode([$result['row'], $message?->toArray()]);
    });

    check('…but not someone with inbox access only (no Manage threads)', function() use ($deliver, $byHeader, $alertId, $support, $inboxOnly, $count) {
        $before = $count($support->id);
        $result = $deliver('mailgun', $byHeader($inboxOnly->email, $alertId(Thread::find()->id($support->id)->status(null)->one()), 'Can I?'));

        return $result['row']['status'] === Inbound::STATUS_REJECTED && $count($support->id) === $before ?: json_encode($result['row']);
    });

    check('…nor a stranger with no account who has the alert’s Message-ID', function() use ($deliver, $byHeader, $alertId, $support, $count) {
        $before = $count($support->id);
        $result = $deliver('mailgun', $byHeader('stranger@example.com', $alertId(Thread::find()->id($support->id)->status(null)->one()), 'Hello'));

        return $result['row']['status'] === Inbound::STATUS_REJECTED && $count($support->id) === $before ?: json_encode($result['row']);
    });

    check('a user-to-user thread needs “View private user-to-user conversations”, as in the CP', function() use ($deliver, $byHeader, $alertId, $direct, $staff, $staffDirect, $count) {
        $before = $count($direct->id);
        $refused = $deliver('mailgun', $byHeader($staff->email, $alertId(Thread::find()->id($direct->id)->status(null)->one()), 'Not my business'));
        $after = $count($direct->id);
        $allowed = $deliver('mailgun', $byHeader($staffDirect->email, $alertId(Thread::find()->id($direct->id)->status(null)->one()), 'Moderator note to you both'));

        return $refused['row']['status'] === Inbound::STATUS_REJECTED && $after === $before
            && $allowed['row']['status'] === Inbound::STATUS_REPLY
            ?: json_encode([$refused['row'], $allowed['row']]);
    });

    check('an email reply is never an internal note', function() use ($deliver, $reply, $staffP, $staff, $latest, $support) {
        $result = $deliver('mailgun', $reply($staffP->replyToken, $staff->email, 'Visible to the guest', ['isInternalNote' => '1']));
        $message = MessageRecord::findOne((int)$result['row']['postedMessageId']);

        return $result['row']['status'] === Inbound::STATUS_REPLY && $message && !$message->isInternalNote ?: json_encode($result['row']);
    });

    // ------------------------------------------------------------------------------------------
    section('Closed conversations');

    check('a guest’s emailed reply reopens a closed support thread as pending, like the guest page', function() use ($plugin, $deliver, $reply, $guest, $guestEmail, $support) {
        $plugin->threads->setStatus(Thread::find()->id($support->id)->status(null)->one(), ThreadStatus::Closed);
        $result = $deliver('mailgun', $reply($guest->replyToken, $guestEmail, 'Actually, one more thing'));

        return $result['row']['status'] === Inbound::STATUS_REPLY && Thread::find()->id($support->id)->status(null)->one()->threadStatus === ThreadStatus::Pending->value
            ?: json_encode($result['row']);
    });

    check('a user’s emailed reply reopens a closed direct thread, like the site’s reply action', function() use ($plugin, $deliver, $reply, $bobP, $bob, $direct) {
        $plugin->threads->setStatus(Thread::find()->id($direct->id)->status(null)->one(), ThreadStatus::Closed);
        $result = $deliver('mailgun', $reply($bobP->replyToken, $bob->email, 'Reopening this'));

        return $result['row']['status'] === Inbound::STATUS_REPLY && Thread::find()->id($direct->id)->status(null)->one()->threadStatus === ThreadStatus::Open->value
            ?: json_encode($result['row']);
    });

    // ------------------------------------------------------------------------------------------
    section('Loops, automation and rate limits');

    check('an out-of-office reply is ignored, not posted', function() use ($inbound, $fixtures, $guest, $guestEmail) {
        $raw = strtr((string)file_get_contents("$fixtures/out-of-office.eml"), ['{{TOKEN}}' => $guest->replyToken, '{{FROM}}' => $guestEmail, '{{MSGID}}' => 'ooo-' . bin2hex(random_bytes(6)) . '@example.org']);
        $result = $inbound->importRaw($raw, 'import', false);
        $row = (new Query())->from([Inbound::TABLE_INBOUND])->where(['id' => $result['id']])->one();

        return $row['status'] === Inbound::STATUS_IGNORED && str_contains((string)$row['reason'], LoopGuard::AUTO_SUBMITTED) ?: json_encode($row);
    });

    check('Precedence: bulk is ignored', function() use ($deliver, $reply, $guest, $guestEmail) {
        $result = $deliver('mailgun', $reply($guest->replyToken, $guestEmail, 'newsletter', ['Precedence' => 'bulk']));

        return $result['row']['status'] === Inbound::STATUS_IGNORED ?: json_encode($result['row']);
    });

    check('mail from the reply mailbox itself, or Pigeon’s From address, is ignored', function() use ($deliver, $reply, $guest, $settings) {
        $own = $deliver('mailgun', $reply($guest->replyToken, FIXTURE_DESK, 'echo'));
        $settings->fromEmail = 'notifications@pigeon.example.com';

        try {
            $from = $deliver('mailgun', $reply($guest->replyToken, 'notifications@pigeon.example.com', 'echo 2'));
        } finally {
            $settings->fromEmail = '';
        }

        return $own['row']['status'] === Inbound::STATUS_IGNORED && $from['row']['status'] === Inbound::STATUS_IGNORED ?: json_encode([$own['row'], $from['row']]);
    });

    check('past the hourly limit, a sender’s mail is dropped and logged', function() use ($deliver, $reply, $guest, $settings, $domain) {
        $settings->inboundRateLimit = 2;

        try {
            $results = [];

            for ($i = 0; $i < 3; $i++) {
                $results[] = $deliver('mailgun', $reply($guest->replyToken, "flood@$domain", "Flood $i"))['result'];
            }
        } finally {
            $settings->inboundRateLimit = 0;
        }

        return $results === [Inbound::RESULT_ACCEPTED, Inbound::RESULT_ACCEPTED, Inbound::RESULT_RATE_LIMITED] ?: json_encode($results);
    });

    check('a cancelled EVENT_BEFORE_PROCESS drops the email, and the event knew who it was from', function() use ($deliver, $reply, $guest, $guestEmail) {
        $seenGuest = null;
        $handler = static function(InboundEmailEvent $event) use (&$seenGuest) {
            $seenGuest = $event->participant?->id;
            $event->isValid = false;
        };
        Event::on(Inbound::class, Inbound::EVENT_BEFORE_PROCESS, $handler);

        try {
            $result = $deliver('mailgun', $reply($guest->replyToken, $guestEmail, 'Vetoed'));
        } finally {
            Event::off(Inbound::class, Inbound::EVENT_BEFORE_PROCESS, $handler);
        }

        return $result['row']['status'] === Inbound::STATUS_IGNORED && $seenGuest === $guest->id ?: json_encode([$result['row'], $seenGuest]);
    });

    // ------------------------------------------------------------------------------------------
    section('Attachments');

    check('content sniffing: a real PNG is a PNG; anything else named .png is not', function() use ($tempDir) {
        $request = new InboundRequest(tempDir: $tempDir);

        return Inbound::contentMatchesExtension($request->writeTemp(tinyPng()), 'png')
            && !Inbound::contentMatchesExtension($request->writeTemp("MZ\x90\x00 not an image"), 'png')
            ?: 'wrong';
    });

    $withFiles = $deliver('postmark', postmarkRequest(postmarkPayload([
        'From' => $guestEmail,
        'FromFull' => ['Email' => $guestEmail, 'Name' => 'Gina'],
        'To' => 'messages+' . $guest->replyToken . '@pigeon.example.com',
        'ToFull' => [['Email' => 'messages+' . $guest->replyToken . '@pigeon.example.com']],
        'TextBody' => 'Screenshots attached',
        'Attachments' => [
            ['Name' => 'screen.png', 'Content' => base64_encode(tinyPng()), 'ContentType' => 'image/png'],
            ['Name' => 'setup.exe', 'Content' => base64_encode("MZ\x90\x00"), 'ContentType' => 'application/octet-stream'],
            ['Name' => 'invoice.png', 'Content' => base64_encode('<?php echo 1; ?>'), 'ContentType' => 'image/png'],
        ],
    ]), tempDir: $tempDir));
    $withFilesMessage = MessageRecord::findOne((int)$withFiles['row']['postedMessageId']);

    check('the allowed file is stored as an asset on the reply, in the thread’s own folder', function() use ($withFilesMessage, $support) {
        $files = $withFilesMessage ? AttachmentRecord::find()->where(['messageId' => $withFilesMessage->id])->all() : [];
        $asset = isset($files[0]) ? Asset::find()->id($files[0]->assetId)->status(null)->one() : null;

        return count($files) === 1 && $files[0]->filename === 'screen.png' && $asset !== null
            && str_contains((string)$asset->getFolder()->path, $support->uid)
            ?: json_encode(array_map(static fn($a) => $a->filename, $files));
    });

    check('the refused files are named in an internal note, with the reason — and nobody is emailed about it', function() use ($support, $queuedFor) {
        $note = MessageRecord::find()->where(['threadId' => $support->id, 'isInternalNote' => true])->orderBy(['id' => SORT_DESC])->one();

        return $note && str_contains($note->body, 'setup.exe — file type not allowed')
            && str_contains($note->body, 'invoice.png — content does not match its extension')
            && $queuedFor((int)$note->id) === 0
            ?: json_encode($note?->body);
    });

    check('the guest never sees that note', function() use ($plugin, $support) {
        foreach ($plugin->messages->getForThread($support->id) as $message) {
            if (str_contains($message->body, 'not kept')) {
                return 'visible';
            }
        }

        return true;
    });

    check('nothing is left in the inbound storage directory afterwards', function() use ($inbound) {
        $left = is_dir($inbound->storageDir()) ? array_diff(scandir($inbound->storageDir()) ?: [], ['.', '..']) : [];

        return $left === [] ?: implode(', ', $left);
    });

    check('with attachments off, files are dropped (and noted) but the reply still posts', function() use ($deliver, $guest, $guestEmail, $settings) {
        $settings->inboundAttachments = false;

        try {
            $result = $deliver('postmark', postmarkRequest(postmarkPayload([
                'From' => $guestEmail,
                'FromFull' => ['Email' => $guestEmail, 'Name' => 'Gina'],
                'To' => 'messages+' . $guest->replyToken . '@pigeon.example.com',
                'ToFull' => [['Email' => 'messages+' . $guest->replyToken . '@pigeon.example.com']],
                'TextBody' => 'No attachments please',
                'Attachments' => [['Name' => 'a.png', 'Content' => base64_encode(tinyPng()), 'ContentType' => 'image/png']],
            ])));
        } finally {
            $settings->inboundAttachments = true;
        }

        return $result['row']['status'] === Inbound::STATUS_REPLY
            && !AttachmentRecord::find()->where(['messageId' => (int)$result['row']['postedMessageId']])->exists()
            ?: json_encode($result['row']);
    });

    // ------------------------------------------------------------------------------------------
    section('IMAP and raw imports');

    check('without the imap extension, the poll explains instead of failing obscurely', function() use ($inbound) {
        if (Inbound::imapAvailable()) {
            return true;
        }

        try {
            $inbound->poll();
        } catch (RuntimeException $e) {
            return str_contains($e->getMessage(), 'imap extension') ?: $e->getMessage();
        }

        return 'no exception';
    });

    check('a raw HTML-only .eml from a user: script, style and quoted part never reach the message', function() use ($inbound, $fixtures, $aliceP, $alice, $direct, $latest) {
        $raw = strtr((string)file_get_contents("$fixtures/html-reply.eml"), ['{{TOKEN}}' => $aliceP->replyToken, '{{FROM}}' => $alice->email, '{{MSGID}}' => 'html-' . bin2hex(random_bytes(6)) . '@example.net']);
        $result = $inbound->importRaw($raw, 'import', false);
        $row = (new Query())->from([Inbound::TABLE_INBOUND])->where(['id' => $result['id']])->one();
        $body = $latest($direct->id)->body;

        return $row['status'] === Inbound::STATUS_REPLY && str_contains($body, 'reset link (https://example.net/reset) fails')
            && !str_contains($body, 'alert(1)') && !str_contains($body, 'color:red') && !str_contains($body, 'old text')
            ?: json_encode([$row, $body]);
    });
} finally {
    // ------------------------------------------------------------------------------------------
    section('Cleaning up');

    foreach ($cleanup['threads'] as $threadId) {
        $assetIds = (new Query())->select('a.assetId')->from(['a' => '{{%pigeon_attachments}}'])
            ->innerJoin(['m' => '{{%pigeon_messages}}'], '[[m.id]] = [[a.messageId]]')->where(['m.threadId' => $threadId])->column();

        foreach (Asset::find()->id($assetIds)->status(null)->all() as $asset) {
            Craft::$app->getElements()->deleteElement($asset, true);
        }

        if ($t = Thread::find()->id($threadId)->status(null)->one()) {
            Craft::$app->getElements()->deleteElement($t, true);
        }
    }

    foreach ($cleanup['users'] as $u) {
        Craft::$app->getElements()->deleteElement($u, true);
    }

    Craft::$app->getDb()->createCommand()->delete(Inbound::TABLE_INBOUND, ['>', 'id', $inboundFloor])->execute();
    Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['>', 'id', $queueFloor])->execute();
    craft\helpers\FileHelper::removeDirectory($tempDir);
    echo '  removed ' . count($cleanup['threads']) . ' threads and ' . count($cleanup['users']) . " users\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
