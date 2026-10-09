<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail;

/**
 * Email addresses and Message-IDs, parsed the way inbound mail actually writes them.
 *
 * Pure functions with no Craft in them, so the whole inbound unit can be lifted into another
 * plugin by changing the namespace. (Copied from CSR, the family reference; keep the two in step.)
 *
 * ## Reply-token addressing
 *
 * A reply address is the desk's own address with a token after a `+`:
 * `support+k3v9…@example.com`. Every mainstream provider delivers plus-addressed mail to the base
 * mailbox, and the token survives the round trip in the `To` header of the customer's reply — which
 * is more reliable than `In-Reply-To`, because plenty of mail clients drop or rewrite that.
 *
 * Tokens are **lower-case** letters and digits. Some relays lower-case the local part of an
 * address on the way through, and a mixed-case secret that arrives lower-cased is a secret that
 * no longer matches anything. Matching is still exact: the token read from the address is
 * lower-cased once and then compared, character for character, against the stored one.
 */
final class Addresses
{
    /** The alphabet reply tokens are drawn from. Lower case only — see the class note. */
    public const TOKEN_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public const TOKEN_LENGTH = 32;

    /**
     * Every address in a header like `"Holt, Justin" <justin@example.com>, ops@example.com`.
     *
     * Commas inside quotes and angle brackets do not split. Group syntax (`undisclosed:;`) yields
     * nothing, which is the right answer for it.
     *
     * @return list<array{email: string, name: string|null}>
     */
    public static function parseList(string $header): array
    {
        $parts = [];
        $current = '';
        $quoted = false;
        $angle = 0;
        $length = strlen($header);

        for ($i = 0; $i < $length; $i++) {
            $char = $header[$i];

            if ($char === '\\' && $quoted && $i + 1 < $length) {
                $current .= $char . $header[++$i];
                continue;
            }

            if ($char === '"') {
                $quoted = !$quoted;
            } elseif (!$quoted && $char === '<') {
                $angle++;
            } elseif (!$quoted && $char === '>') {
                $angle = max(0, $angle - 1);
            } elseif (!$quoted && $angle === 0 && ($char === ',' || $char === ';')) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;
        $addresses = [];

        foreach ($parts as $part) {
            $parsed = self::parseOne($part);

            if ($parsed !== null) {
                $addresses[] = $parsed;
            }
        }

        return $addresses;
    }

    /** @return array{email: string, name: string|null}|null */
    public static function parseOne(string $value): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(.*)<([^<>]+)>\s*$/s', $value, $m) === 1) {
            $name = trim(trim($m[1]), '"');
            $name = stripslashes($name);
            $email = trim($m[2]);
        } else {
            // A bare address, possibly with a comment after it: `jo@example.com (Jo)`.
            $name = null;
            $email = trim((string)preg_replace('/\s*\(.*\)\s*$/', '', $value));

            if (preg_match('/\(([^)]*)\)/', $value, $m) === 1) {
                $name = trim($m[1]);
            }
        }

        $email = self::normalize($email);

        if ($email === null) {
            return null;
        }

        return ['email' => $email, 'name' => $name !== null && $name !== '' ? $name : null];
    }

    /** Lower-cased and validated, or null. */
    public static function normalize(?string $email): ?string
    {
        $email = strtolower(trim((string)$email, " \t\r\n<>\"'"));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * `support+token@example.com` from `support@example.com`.
     *
     * Null when the base address is not a usable address, so callers fall back to sending without
     * a reply address rather than sending a broken one.
     */
    public static function replyAddress(string $base, string $token): ?string
    {
        $base = self::normalize($base);

        if ($base === null || $token === '') {
            return null;
        }

        [$local, $domain] = explode('@', $base, 2);
        // An address that already carries a tag keeps only its base: one `+` per address.
        $local = explode('+', $local, 2)[0];

        return $local . '+' . $token . '@' . $domain;
    }

    /**
     * The token in a reply address, if this recipient is one of ours.
     *
     * Only an address on the desk's own mailbox counts — `support+x@example.com` when the desk is
     * `support@example.com` — so a token-shaped tag on somebody else's address is ignored rather
     * than looked up.
     */
    public static function tokenFrom(string $recipient, string $base): ?string
    {
        $recipient = self::normalize($recipient);
        $base = self::normalize($base);

        if ($recipient === null || $base === null) {
            return null;
        }

        [$baseLocal, $baseDomain] = explode('@', $base, 2);
        $baseLocal = explode('+', $baseLocal, 2)[0];
        [$local, $domain] = explode('@', $recipient, 2);

        if ($domain !== $baseDomain || !str_starts_with($local, $baseLocal . '+')) {
            return null;
        }

        $token = substr($local, strlen($baseLocal) + 1);

        return self::isToken($token) ? $token : null;
    }

    public static function isToken(string $value): bool
    {
        return preg_match('/^[a-z0-9]{' . self::TOKEN_LENGTH . '}$/', $value) === 1;
    }

    /** Whether an address is the desk's own mailbox or one of its tagged variants. */
    public static function isOwn(string $address, string $base): bool
    {
        $address = self::normalize($address);
        $base = self::normalize($base);

        if ($address === null || $base === null) {
            return false;
        }

        if ($address === $base) {
            return true;
        }

        [$baseLocal, $baseDomain] = explode('@', $base, 2);
        [$local, $domain] = explode('@', $address, 2);

        return $domain === $baseDomain && explode('+', $local, 2)[0] === explode('+', $baseLocal, 2)[0];
    }

    /**
     * Every Message-ID in an `In-Reply-To` or `References` header, without the angle brackets.
     *
     * @return list<string>
     */
    public static function messageIds(?string $header): array
    {
        $header = trim((string)$header);

        if ($header === '') {
            return [];
        }

        if (preg_match_all('/<([^<>\s]+)>/', $header, $m) > 0) {
            $ids = $m[1];
        } else {
            $ids = preg_split('/\s+/', $header) ?: [];
        }

        $clean = [];

        foreach ($ids as $id) {
            $id = self::normalizeMessageId($id);

            if ($id !== null) {
                $clean[$id] = true;
            }
        }

        return array_map('strval', array_keys($clean));
    }

    public static function normalizeMessageId(?string $id): ?string
    {
        $id = trim((string)$id, " \t\r\n<>");

        return $id !== '' && strlen($id) <= 998 && !str_contains($id, ' ') ? $id : null;
    }

    /**
     * The key a Message-ID is stored and looked up by: a SHA-256 of its lower-cased form.
     *
     * Hashed so the column is fixed-width and indexable whatever the id's length, and lower-cased
     * because some relays change the case of the domain half.
     */
    public static function messageHash(string $id): string
    {
        return hash('sha256', strtolower($id));
    }
}
