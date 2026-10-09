<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail;

/**
 * One received email, the same shape whichever provider delivered it.
 *
 * Postmark posts JSON, Mailgun and SendGrid post forms, an IMAP mailbox hands over raw RFC 822 —
 * and every one of them is turned into this before anything decides what the email means. The
 * pipeline after this point never knows where the message came from, which is what lets a fourth
 * provider be added by writing one parser.
 *
 * Plain PHP with no Craft in it, so it travels: `toArray()`/`fromArray()` are how it is stored
 * between the webhook (which must answer quickly) and the queue job that does the work.
 */
final class InboundMessage
{
    /** `postmark`, `mailgun`, `sendgrid`, `imap` or `import`. */
    public string $provider = '';

    public string $fromEmail = '';

    public ?string $fromName = null;

    /** @var list<string> Lower-cased addresses from the `To` header. */
    public array $to = [];

    /** @var list<string> Lower-cased addresses from the `Cc` header. */
    public array $cc = [];

    /**
     * @var list<string> Envelope recipients the provider reported (`RCPT TO`), which is where a
     *                   Bcc'd or forwarded copy shows its real destination.
     */
    public array $envelopeTo = [];

    public string $subject = '';

    /** The plain-text part, as sent — quotes and signature included. */
    public string $text = '';

    /** The HTML part, as sent. Never rendered; only ever turned into text. */
    public string $html = '';

    /** Without angle brackets. */
    public ?string $messageId = null;

    /** @var list<string> */
    public array $inReplyTo = [];

    /** @var list<string> */
    public array $references = [];

    /**
     * @var array<string, list<string>> Every header, lower-cased name => values in order. Loop
     *                                  detection reads `auto-submitted`, `precedence` and friends
     *                                  from here.
     */
    public array $headers = [];

    /** @var list<InboundAttachment> */
    public array $attachments = [];

    /** A tag the provider extracted from the recipient itself (Postmark's `MailboxHash`). */
    public ?string $mailboxHash = null;

    /** The first value of a header, or null. */
    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values !== [] ? (string)$values[0] : null;
    }

    public function addHeader(string $name, string $value): void
    {
        $this->headers[strtolower(trim($name))][] = trim($value);
    }

    /**
     * Fill the threading fields from the headers, for parsers that only produce headers.
     */
    public function applyHeaders(): void
    {
        $this->messageId ??= Addresses::normalizeMessageId($this->header('message-id'));
        $this->inReplyTo = $this->inReplyTo ?: Addresses::messageIds($this->header('in-reply-to'));
        $this->references = $this->references ?: Addresses::messageIds(implode(' ', $this->headers['references'] ?? []));

        if ($this->fromEmail === '' && ($from = $this->header('from')) !== null) {
            $parsed = Addresses::parseList($from)[0] ?? null;
            $this->fromEmail = $parsed['email'] ?? '';
            $this->fromName ??= $parsed['name'] ?? null;
        }

        if ($this->to === [] && ($to = $this->header('to')) !== null) {
            $this->to = array_column(Addresses::parseList($to), 'email');
        }

        if ($this->cc === [] && ($cc = $this->header('cc')) !== null) {
            $this->cc = array_column(Addresses::parseList($cc), 'email');
        }

        if ($this->subject === '' && ($subject = $this->header('subject')) !== null) {
            $this->subject = $subject;
        }
    }

    /** @return list<string> Every address it was sent to, deduplicated. */
    public function allRecipients(): array
    {
        $all = [];

        foreach ([...$this->envelopeTo, ...$this->to, ...$this->cc] as $address) {
            $normalized = Addresses::normalize($address);

            if ($normalized !== null) {
                $all[$normalized] = true;
            }
        }

        return array_map('strval', array_keys($all));
    }

    /** @return list<string> In-Reply-To first, then References newest-first. */
    public function threadIds(): array
    {
        return array_values(array_unique([...$this->inReplyTo, ...array_reverse($this->references)]));
    }

    /**
     * The key that makes a redelivery recognisable: the Message-ID when there is one, otherwise a
     * hash of what the message says, so a provider retrying the same webhook is still caught.
     */
    public function dedupeHash(): string
    {
        if ($this->messageId !== null) {
            return Addresses::messageHash($this->messageId);
        }

        return hash('sha256', implode("\n", [
            'no-message-id',
            strtolower($this->fromEmail),
            $this->subject,
            (string)$this->header('date'),
            substr($this->text !== '' ? $this->text : $this->html, 0, 4096),
        ]));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = get_object_vars($this);
        $data['attachments'] = array_map(static fn(InboundAttachment $a) => $a->toArray(), $this->attachments);

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $message = new self();

        foreach (get_object_vars($message) as $name => $default) {
            if (!array_key_exists($name, $data)) {
                continue;
            }

            $value = $data[$name];

            if ($name === 'attachments') {
                $message->attachments = array_values(array_map(
                    static fn(array $a) => InboundAttachment::fromArray($a),
                    array_filter(is_array($value) ? $value : [], 'is_array'),
                ));
                continue;
            }

            if (is_array($default)) {
                $message->$name = is_array($value) ? $value : [];
            } elseif (is_string($default)) {
                $message->$name = (string)$value;
            } else {
                $message->$name = $value !== null ? (string)$value : null;
            }
        }

        return $message;
    }
}
