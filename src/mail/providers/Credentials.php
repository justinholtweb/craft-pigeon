<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail\providers;

/**
 * Constant-time checks shared by the providers.
 */
final class Credentials
{
    /**
     * HTTP basic auth against the configured pair.
     *
     * Both halves are always compared, so the time taken does not say whether the username was
     * right. An empty configured value never matches — "no password set" must not mean "any
     * password".
     */
    public static function basicAuthMatches(?string $user, ?string $password, string $expectedUser, string $expectedPassword): bool
    {
        if ($expectedUser === '' || $expectedPassword === '') {
            return false;
        }

        $userOk = hash_equals($expectedUser, (string)$user);
        $passwordOk = hash_equals($expectedPassword, (string)$password);

        return $userOk && $passwordOk;
    }

    /** Whether a provider's timestamp is close enough to now to be this delivery, not a replay. */
    public static function withinWindow(string $timestamp, int $now, int $window): bool
    {
        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return false;
        }

        return abs($now - (int)$timestamp) <= max(1, $window);
    }
}
