<?php

namespace justinholtweb\pigeontests\unit\helpers;

use Craft;
use craft\test\TestCase;
use justinholtweb\pigeon\helpers\RateLimiter;

class RateLimiterTest extends TestCase
{
    private string $key;

    protected function _before(): void
    {
        parent::_before();
        $this->key = 'pigeon-test-' . uniqid();
        Craft::$app->getCache()->flush();
    }

    public function testAllowsUpToTheLimitThenBlocks(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            self::assertTrue(RateLimiter::hit($this->key, 3, 60), "hit $i should be allowed");
        }

        self::assertFalse(RateLimiter::hit($this->key, 3, 60));
        self::assertFalse(RateLimiter::hit($this->key, 3, 60));
    }

    public function testKeysAreIndependent(): void
    {
        self::assertTrue(RateLimiter::hit($this->key . 'a', 1, 60));
        self::assertFalse(RateLimiter::hit($this->key . 'a', 1, 60));

        // A different identifier still has its own budget.
        self::assertTrue(RateLimiter::hit($this->key . 'b', 1, 60));
    }

    public function testWindowExpiryResetsTheCounter(): void
    {
        self::assertTrue(RateLimiter::hit($this->key, 1, 1));
        self::assertFalse(RateLimiter::hit($this->key, 1, 1));

        // Simulate the window elapsing rather than sleeping.
        Craft::$app->getCache()->delete('pigeon:rl:' . md5($this->key));

        self::assertTrue(RateLimiter::hit($this->key, 1, 1));
    }

    public function testZeroLimitBlocksEverything(): void
    {
        self::assertFalse(RateLimiter::hit($this->key, 0, 60));
    }
}
