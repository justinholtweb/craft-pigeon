<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail\providers;

use justinholtweb\pigeon\mail\Addresses;
use justinholtweb\pigeon\mail\InboundAttachment;
use justinholtweb\pigeon\mail\InboundMessage;
use justinholtweb\pigeon\mail\InboundRequest;

/**
 * Postmark's inbound webhook: JSON, authenticated with HTTP basic auth.
 *
 * Postmark does not sign inbound deliveries. What it supports — and documents as the way to secure
 * the endpoint — is credentials in the webhook URL (`https://user:pass@example.com/…`), which it
 * sends as an `Authorization: Basic` header. That is what is checked, in constant time. There is no
 * timestamp to bound a replay with, so redelivery is caught downstream instead, by the Message-ID.
 *
 * @see https://postmarkapp.com/developer/webhooks/inbound-webhook
 */
final class Postmark implements ProviderInterface
{
    public function __construct(
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    public static function handle(): string
    {
        return 'postmark';
    }

    public function isConfigured(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }

    public function verify(InboundRequest $request, int $now): ?string
    {
        if (!$this->isConfigured()) {
            return 'not configured';
        }

        return Credentials::basicAuthMatches($request->authUser, $request->authPassword, $this->username, $this->password)
            ? null
            : 'bad credentials';
    }

    public function parse(InboundRequest $request): InboundMessage
    {
        $data = json_decode($request->rawBody, true);
        $data = is_array($data) ? $data : [];

        $message = new InboundMessage();
        $message->provider = self::handle();

        foreach (is_array($data['Headers'] ?? null) ? $data['Headers'] : [] as $header) {
            if (is_array($header) && isset($header['Name'], $header['Value'])) {
                $message->addHeader((string)$header['Name'], (string)$header['Value']);
            }
        }

        $from = is_array($data['FromFull'] ?? null) ? $data['FromFull'] : [];
        $message->fromEmail = Addresses::normalize((string)($from['Email'] ?? $data['From'] ?? '')) ?? '';
        $message->fromName = isset($from['Name']) && $from['Name'] !== '' ? (string)$from['Name'] : null;
        $message->to = self::addresses($data['ToFull'] ?? null, (string)($data['To'] ?? ''));
        $message->cc = self::addresses($data['CcFull'] ?? null, (string)($data['Cc'] ?? ''));

        if (($original = Addresses::normalize((string)($data['OriginalRecipient'] ?? ''))) !== null) {
            $message->envelopeTo[] = $original;
        }

        $message->subject = (string)($data['Subject'] ?? '');
        $message->text = (string)($data['TextBody'] ?? '');
        $message->html = (string)($data['HtmlBody'] ?? '');
        $message->mailboxHash = isset($data['MailboxHash']) && $data['MailboxHash'] !== '' ? strtolower((string)$data['MailboxHash']) : null;

        foreach (is_array($data['Attachments'] ?? null) ? $data['Attachments'] : [] as $attachment) {
            if (!is_array($attachment) || !isset($attachment['Content'])) {
                continue;
            }

            $bytes = base64_decode((string)$attachment['Content'], true);

            if ($bytes === false) {
                continue;
            }

            $message->attachments[] = new InboundAttachment(
                (string)($attachment['Name'] ?? 'attachment'),
                strtolower((string)($attachment['ContentType'] ?? 'application/octet-stream')),
                strlen($bytes),
                $request->writeTemp($bytes),
                Addresses::normalizeMessageId(isset($attachment['ContentID']) ? (string)$attachment['ContentID'] : null),
            );
        }

        // Postmark's own `MessageID` is *its* id, not the sender's; the sender's Message-ID is in
        // the headers, which is the one replies refer back to.
        $message->applyHeaders();

        return $message;
    }

    /** @return list<string> */
    private static function addresses(mixed $full, string $fallback): array
    {
        $list = [];

        if (is_array($full)) {
            foreach ($full as $entry) {
                if (is_array($entry) && ($email = Addresses::normalize((string)($entry['Email'] ?? ''))) !== null) {
                    $list[] = $email;
                }
            }
        }

        return $list !== [] ? $list : array_column(Addresses::parseList($fallback), 'email');
    }
}
