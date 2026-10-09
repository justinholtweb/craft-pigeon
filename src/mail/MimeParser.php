<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail;

use Throwable;

/**
 * A raw RFC 822 message — what an IMAP mailbox, SendGrid's raw mode or a `.eml` file holds —
 * turned into an {@see InboundMessage}.
 *
 * Pure PHP (mbstring and iconv, both Craft requirements) rather than ext-mailparse or ext-imap:
 * neither is installed on most hosts, PHP 8.4 removed imap from core altogether, and a support desk
 * whose email stops working after a PHP upgrade is a support desk with a support ticket of its own.
 *
 * It handles what mail clients actually send: nested multiparts, base64 and quoted-printable,
 * encoded-word headers, RFC 2231 file names, and charsets other than UTF-8. It deliberately does
 * not render anything — HTML is kept as a string for {@see ReplyParser} to turn into text.
 */
final class MimeParser
{
    /** Deeper than any real mail client nests; deeper still is somebody trying something. */
    private const MAX_DEPTH = 12;

    /**
     * @param callable(string): string $store Writes attachment bytes somewhere and returns the path.
     */
    public static function parse(string $raw, callable $store, string $provider = 'import'): InboundMessage
    {
        $message = new InboundMessage();
        $message->provider = $provider;

        [$head, $body] = self::split($raw);
        $headers = self::headers($head);

        foreach ($headers as [$name, $value]) {
            $message->addHeader($name, $value);
        }

        self::walk($headers, $body, $message, $store, 0);
        $message->applyHeaders();

        return $message;
    }

    /**
     * Headers and body, split at the first blank line. Line endings are normalised to `\n` first,
     * because mail arrives with CRLF, LF and — from some Windows relays — both in one message.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);

        if (str_starts_with($raw, "\n")) {
            return ['', substr($raw, 1)];
        }

        $position = strpos($raw, "\n\n");

        return $position === false ? [$raw, ''] : [substr($raw, 0, $position), substr($raw, $position + 2)];
    }

    /**
     * Unfolded, decoded headers in order. A header can repeat (`Received`, `References` on a bad
     * day), so this is a list rather than a map.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function headers(string $head): array
    {
        $head = (string)preg_replace("/\n[ \t]+/", ' ', str_replace("\r\n", "\n", $head));
        $headers = [];

        foreach (explode("\n", $head) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));

            if ($name === '' || preg_match('/^[!-9;-~]+$/', $name) !== 1) {
                continue;
            }

            $headers[] = [$name, self::decodeHeader(trim($value))];
        }

        return $headers;
    }

    /** `=?UTF-8?B?…?=` and friends, to UTF-8. */
    public static function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }

        return mb_decode_mimeheader($value);
    }

    /**
     * A header value and its parameters: `text/plain; charset="iso-8859-1"` →
     * `['text/plain', ['charset' => 'iso-8859-1']]`. Handles RFC 2231 continuations and
     * charset-tagged values (`filename*=utf-8''r%C3%A9sum%C3%A9.pdf`).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function parameters(string $value): array
    {
        $pieces = [];
        $current = '';
        $quoted = false;

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '"') {
                $quoted = !$quoted;
            }

            if ($char === ';' && !$quoted) {
                $pieces[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $pieces[] = $current;
        $main = strtolower(trim((string)array_shift($pieces)));
        $params = [];
        $continued = [];

        foreach ($pieces as $piece) {
            if (!str_contains($piece, '=')) {
                continue;
            }

            [$key, $val] = explode('=', $piece, 2);
            $key = strtolower(trim($key));
            $val = trim($val);

            if (str_starts_with($val, '"') && str_ends_with($val, '"') && strlen($val) >= 2) {
                $val = stripcslashes(substr($val, 1, -1));
            }

            if (preg_match('/^([a-z0-9_-]+)\*(\d+)(\*?)$/', $key, $m) === 1) {
                $continued[$m[1]][(int)$m[2]] = [$val, $m[3] === '*'];
                continue;
            }

            if (str_ends_with($key, '*')) {
                $params[substr($key, 0, -1)] = self::decodeExtended($val, true);
                continue;
            }

            $params[$key] = self::decodeHeader($val);
        }

        foreach ($continued as $key => $sections) {
            ksort($sections);
            $joined = '';
            $first = true;

            foreach ($sections as [$val, $encoded]) {
                $joined .= $encoded ? self::decodeExtended($val, $first) : $val;
                $first = false;
            }

            $params[$key] = $joined;
        }

        return [$main, $params];
    }

    /** `utf-8'en'r%C3%A9sum%C3%A9` → `résumé`. */
    private static function decodeExtended(string $value, bool $hasCharset): string
    {
        $charset = 'utf-8';

        if ($hasCharset && substr_count($value, "'") >= 2) {
            [$charset, , $value] = explode("'", $value, 3);
        }

        return self::toUtf8(rawurldecode($value), $charset ?: 'utf-8');
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     * @param callable(string): string $store
     */
    private static function walk(array $headers, string $body, InboundMessage $message, callable $store, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $header = static function(string $name) use ($headers): ?string {
            foreach ($headers as [$key, $value]) {
                if ($key === $name) {
                    return $value;
                }
            }

            return null;
        };

        [$type, $params] = self::parameters($header('content-type') ?? 'text/plain');
        $type = $type !== '' ? $type : 'text/plain';
        [$disposition, $dispositionParams] = self::parameters($header('content-disposition') ?? '');

        if (str_starts_with($type, 'multipart/') && isset($params['boundary']) && $params['boundary'] !== '') {
            foreach (self::parts($body, $params['boundary']) as $part) {
                [$partHead, $partBody] = self::split($part);
                self::walk(self::headers($partHead), $partBody, $message, $store, $depth + 1);
            }

            return;
        }

        $bytes = self::decodeBody($body, strtolower(trim((string)$header('content-transfer-encoding'))));
        $filename = $dispositionParams['filename'] ?? $params['name'] ?? null;
        $isAttachment = $disposition === 'attachment'
            || $type === 'message/rfc822'
            || ($filename !== null && $disposition !== 'inline' && !str_starts_with($type, 'text/'));

        if (!$isAttachment && $type === 'text/plain' && $message->text === '') {
            $message->text = self::toUtf8($bytes, $params['charset'] ?? 'utf-8');

            return;
        }

        if (!$isAttachment && $type === 'text/html' && $message->html === '') {
            $message->html = self::toUtf8($bytes, $params['charset'] ?? 'utf-8');

            return;
        }

        // Inline parts without a disposition of `attachment` — the logo in somebody's signature,
        // the tracking pixel — are not something the desk needs to see.
        if (!$isAttachment) {
            return;
        }

        $filename = $filename !== null && $filename !== '' ? $filename : ($type === 'message/rfc822' ? 'message.eml' : 'attachment');

        $message->attachments[] = new InboundAttachment(
            $filename,
            $type,
            strlen($bytes),
            $store($bytes),
            Addresses::normalizeMessageId($header('content-id')),
        );
    }

    /** @return list<string> The parts between the boundaries, preamble and epilogue dropped. */
    private static function parts(string $body, string $boundary): array
    {
        $segments = explode("\n--" . $boundary, "\n" . $body);
        array_shift($segments);
        $parts = [];

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '--')) {
                break;
            }

            // Whatever follows the boundary on its own line (usually nothing, sometimes spaces).
            $newline = strpos($segment, "\n");
            $parts[] = $newline === false ? '' : substr($segment, $newline + 1);
        }

        return $parts;
    }

    public static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string)base64_decode((string)preg_replace('/[^A-Za-z0-9+\/=]/', '', $body), false),
            'quoted-printable' => quoted_printable_decode(str_replace("\n", "\r\n", $body)),
            default => $body,
        };
    }

    public static function toUtf8(string $text, string $charset): string
    {
        $charset = strtolower(trim($charset, " \t\"'"));

        if ($charset === '' || $charset === 'utf-8' || $charset === 'utf8' || $charset === 'us-ascii') {
            return mb_scrub($text, 'UTF-8');
        }

        try {
            $converted = mb_convert_encoding($text, 'UTF-8', $charset);

            if (is_string($converted)) {
                return $converted;
            }
        } catch (Throwable) {
            // mbstring does not know the name; iconv knows a few more.
        }

        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);

        return is_string($converted) ? $converted : mb_scrub($text, 'UTF-8');
    }
}
