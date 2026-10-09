<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail\providers;

use justinholtweb\pigeon\mail\Addresses;
use justinholtweb\pigeon\mail\InboundAttachment;
use justinholtweb\pigeon\mail\InboundMessage;
use justinholtweb\pigeon\mail\InboundRequest;

/**
 * Mailgun's inbound routes, with the `forward()` action: a form post carrying the parsed message.
 *
 * Every post is signed: `signature` is the hex HMAC-SHA256 of `timestamp . token`, keyed with the
 * account's HTTP webhook signing key. Three checks, as Mailgun recommends: the HMAC (constant
 * time), the timestamp within the replay window, and the token never seen before — the last
 * through a callback so the plugin can keep the seen tokens in its cache.
 *
 * The `store()` + notify variant, which posts a URL to fetch the message from, is not supported:
 * fetching it would mean an outgoing request with the account's API key to a URL taken from the
 * request.
 *
 * @see https://documentation.mailgun.com/docs/mailgun/user-manual/receive-forward-store/
 */
final class Mailgun implements ProviderInterface
{
    /**
     * @param callable(string $token, int $ttl): bool $remember Records a token; false if it was
     *                                                          already recorded (a replay).
     */
    public function __construct(
        private readonly string $signingKey,
        private readonly int $replayWindow,
        private $remember,
    ) {
    }

    public static function handle(): string
    {
        return 'mailgun';
    }

    public function isConfigured(): bool
    {
        return $this->signingKey !== '';
    }

    public function verify(InboundRequest $request, int $now): ?string
    {
        if (!$this->isConfigured()) {
            return 'not configured';
        }

        $timestamp = (string)$request->field('timestamp');
        $token = (string)$request->field('token');
        $signature = strtolower((string)$request->field('signature'));

        if ($timestamp === '' || $token === '' || $signature === '') {
            return 'unsigned';
        }

        $expected = hash_hmac('sha256', $timestamp . $token, $this->signingKey);

        if (!hash_equals($expected, $signature)) {
            return 'bad signature';
        }

        if (!Credentials::withinWindow($timestamp, $now, $this->replayWindow)) {
            return 'stale timestamp';
        }

        // Only after the signature is known good, so a forger cannot burn real tokens.
        if (!($this->remember)($token, max(60, $this->replayWindow * 2))) {
            return 'replayed token';
        }

        return null;
    }

    public function parse(InboundRequest $request): InboundMessage
    {
        $message = new InboundMessage();
        $message->provider = self::handle();

        $headers = json_decode((string)$request->field('message-headers'), true);

        foreach (is_array($headers) ? $headers : [] as $pair) {
            if (is_array($pair) && isset($pair[0], $pair[1])) {
                $message->addHeader((string)$pair[0], (string)$pair[1]);
            }
        }

        $from = Addresses::parseList((string)($request->field('from') ?? $message->header('from') ?? ''))[0] ?? null;
        $message->fromEmail = $from['email'] ?? (Addresses::normalize($request->field('sender')) ?? '');
        $message->fromName = $from['name'] ?? null;
        $message->envelopeTo = array_column(Addresses::parseList((string)$request->field('recipient')), 'email');
        $message->to = array_column(Addresses::parseList((string)($request->field('To') ?? $message->header('to') ?? '')), 'email');
        $message->cc = array_column(Addresses::parseList((string)($request->field('Cc') ?? $message->header('cc') ?? '')), 'email');
        $message->subject = (string)($request->field('subject') ?? $message->header('subject') ?? '');
        $message->text = (string)$request->field('body-plain');
        $message->html = (string)$request->field('body-html');

        if ($message->header('message-id') === null && ($id = $request->field('Message-Id')) !== null) {
            $message->addHeader('Message-Id', $id);
        }

        foreach (['In-Reply-To', 'References'] as $name) {
            if ($message->header($name) === null && ($value = $request->field($name)) !== null) {
                $message->addHeader($name, $value);
            }
        }

        $count = min(100, (int)$request->field('attachment-count'));

        for ($i = 1; $i <= $count; $i++) {
            $file = $request->files['attachment-' . $i] ?? null;

            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_file($file['tmp_name'])) {
                continue;
            }

            $message->attachments[] = new InboundAttachment(
                (string)$file['name'],
                strtolower((string)$file['type']),
                (int)$file['size'],
                (string)$file['tmp_name'],
            );
        }

        $message->applyHeaders();

        return $message;
    }
}
