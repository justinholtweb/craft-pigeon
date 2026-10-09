<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail\providers;

use justinholtweb\pigeon\mail\Addresses;
use justinholtweb\pigeon\mail\InboundAttachment;
use justinholtweb\pigeon\mail\InboundMessage;
use justinholtweb\pigeon\mail\InboundRequest;
use justinholtweb\pigeon\mail\MimeParser;
use justinholtweb\pigeon\mail\MultipartParser;

/**
 * SendGrid Inbound Parse, verified one of two ways.
 *
 * **Signature** (preferred). With a security policy attached to the Parse webhook, SendGrid signs
 * every post with ECDSA (P-256, SHA-256) over `timestamp . raw body`, sending the base64 DER
 * signature in `X-Twilio-Email-Event-Webhook-Signature` and the timestamp in
 * `X-Twilio-Email-Event-Webhook-Timestamp`. The public key from the policy is all Pigeon stores.
 * The timestamp is signed, so it bounds replays too.
 *
 * The catch, and it is PHP's: the post is `multipart/form-data`, and PHP discards a multipart raw
 * body after parsing it into `$_POST`. Verifying needs the webhook route served with
 * `enable_post_data_reading = Off`; {@see MultipartParser} then does the parsing. Without that the
 * raw body is empty and the delivery is refused — loudly, with that reason in the log.
 *
 * **Basic auth** (fallback). Credentials in the Parse URL, compared in constant time, for hosts
 * where the PHP setting cannot be changed. Used only when no public key is set.
 *
 * Both the default "parsed" payload and "POST the raw, full MIME message" are read.
 *
 * @see https://www.twilio.com/docs/sendgrid/for-developers/parsing-email/securing-your-parse-webhooks
 */
final class SendGrid implements ProviderInterface
{
    public const SIGNATURE_HEADER = 'x-twilio-email-event-webhook-signature';
    public const TIMESTAMP_HEADER = 'x-twilio-email-event-webhook-timestamp';

    public function __construct(
        private readonly string $publicKey,
        private readonly string $username,
        private readonly string $password,
        private readonly int $replayWindow,
    ) {
    }

    public static function handle(): string
    {
        return 'sendgrid';
    }

    public function isConfigured(): bool
    {
        return $this->publicKey !== '' || ($this->username !== '' && $this->password !== '');
    }

    public function usesSignature(): bool
    {
        return $this->publicKey !== '';
    }

    public function verify(InboundRequest $request, int $now): ?string
    {
        if (!$this->isConfigured()) {
            return 'not configured';
        }

        if (!$this->usesSignature()) {
            return Credentials::basicAuthMatches($request->authUser, $request->authPassword, $this->username, $this->password)
                ? null
                : 'bad credentials';
        }

        $signature = (string)$request->header(self::SIGNATURE_HEADER);
        $timestamp = (string)$request->header(self::TIMESTAMP_HEADER);

        if ($signature === '' || $timestamp === '') {
            return 'unsigned';
        }

        if ($request->rawBody === '') {
            return 'raw body unavailable (serve the webhook with enable_post_data_reading = Off)';
        }

        $key = self::pem($this->publicKey);
        $decoded = base64_decode($signature, true);

        if ($key === null || $decoded === false) {
            return 'bad signature';
        }

        $valid = @openssl_verify($timestamp . $request->rawBody, $decoded, $key, OPENSSL_ALGO_SHA256);

        if ($valid !== 1) {
            return 'bad signature';
        }

        if (!Credentials::withinWindow($timestamp, $now, $this->replayWindow)) {
            return 'stale timestamp';
        }

        return null;
    }

    /** The public key as PEM, whether it was pasted as PEM or as SendGrid's bare base64 DER. */
    public static function pem(string $key): ?string
    {
        $key = trim($key);

        if ($key === '') {
            return null;
        }

        if (!str_contains($key, '-----BEGIN')) {
            $key = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $key) ?? '', 64, "\n") . "-----END PUBLIC KEY-----\n";
        }

        return openssl_pkey_get_public($key) !== false ? $key : null;
    }

    public function parse(InboundRequest $request): InboundMessage
    {
        $fields = $request->post;
        $files = $request->files;

        // PHP did not parse it (the signed route): do it here, over the bytes just verified.
        if ($fields === [] && $request->rawBody !== '' && $request->contentType() === 'multipart/form-data') {
            [$fields, $files] = MultipartParser::parse(
                $request->rawBody,
                (string)$request->header('content-type'),
                $request->writeTemp(...),
            );
        }

        $field = static fn(string $name): string => is_scalar($fields[$name] ?? null) ? (string)$fields[$name] : '';

        // "POST the raw, full MIME message": the whole email is in one field.
        if ($field('email') !== '') {
            $message = MimeParser::parse($field('email'), $request->writeTemp(...), self::handle());
            $message->envelopeTo = self::envelopeRecipients($field('envelope'));

            return $message;
        }

        $message = new InboundMessage();
        $message->provider = self::handle();

        [$head] = MimeParser::split($field('headers') . "\n\n");

        foreach (MimeParser::headers($head) as [$name, $value]) {
            $message->addHeader($name, $value);
        }

        $from = Addresses::parseList($field('from'))[0] ?? null;
        $message->fromEmail = $from['email'] ?? '';
        $message->fromName = $from['name'] ?? null;
        $message->to = array_column(Addresses::parseList($field('to')), 'email');
        $message->cc = array_column(Addresses::parseList($field('cc')), 'email');
        $message->envelopeTo = self::envelopeRecipients($field('envelope'));
        $message->subject = $field('subject');
        $message->text = $field('text');
        $message->html = $field('html');

        $info = json_decode($field('attachment-info'), true);
        $count = min(100, (int)$field('attachments'));

        for ($i = 1; $i <= $count; $i++) {
            $file = $files['attachment' . $i] ?? null;

            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_file((string)$file['tmp_name'])) {
                continue;
            }

            $meta = is_array($info) && is_array($info['attachment' . $i] ?? null) ? $info['attachment' . $i] : [];

            $message->attachments[] = new InboundAttachment(
                (string)($meta['filename'] ?? $file['name']),
                strtolower((string)($meta['type'] ?? $file['type'])),
                (int)$file['size'],
                (string)$file['tmp_name'],
                Addresses::normalizeMessageId(isset($meta['content-id']) ? (string)$meta['content-id'] : null),
            );
        }

        $message->applyHeaders();

        return $message;
    }

    /** @return list<string> */
    private static function envelopeRecipients(string $envelope): array
    {
        $data = json_decode($envelope, true);
        $to = is_array($data) ? ($data['to'] ?? []) : [];
        $list = [];

        foreach (is_array($to) ? $to : [$to] as $address) {
            if (is_string($address) && ($email = Addresses::normalize($address)) !== null) {
                $list[] = $email;
            }
        }

        return $list;
    }
}
