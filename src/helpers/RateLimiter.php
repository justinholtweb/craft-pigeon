<?php

namespace justinholtweb\pigeon\helpers;

use Craft;

class RateLimiter
{
    /**
     * Fixed-window rate limiter backed by Craft's cache. Returns true if the
     * action is allowed (and counts it), false if the limit is exceeded.
     */
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $cache = Craft::$app->getCache();
        $cacheKey = 'pigeon:rl:' . md5($key);

        $count = (int)$cache->get($cacheKey);
        if ($count >= $max) {
            return false;
        }

        $cache->set($cacheKey, $count + 1, $windowSeconds);

        return true;
    }
}
