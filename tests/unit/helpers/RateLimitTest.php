<?php

namespace justinholtweb\pigeontests\unit\helpers;

use Craft;
use craft\test\TestCase;
use justinholtweb\pigeon\helpers\RateLimit;

/**
 * The budgets in front of Pigeon's guest routes. Until 5.0.4 there was one, keyed on the client's
 * address and the email together, so a client that changed the email every time was never limited.
 */
class RateLimitTest extends TestCase
{
    private string $bucket;

    protected function _before(): void
    {
        parent::_before();
        $this->bucket = 'test-' . uniqid();
        Craft::$app->getCache()->flush();
    }

    public function testAllowsUpToTheLimitThenBlocks(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            self::assertTrue(RateLimit::allowWindow($this->bucket, 3, 300), "request $i should be allowed");
        }

        self::assertFalse(RateLimit::allowWindow($this->bucket, 3, 300));
    }

    public function testTheClientBudgetIgnoresWhatIsBeingAskedFor(): void
    {
        // The 5.0 bug: a new email every time was a new budget every time. The client budget
        // doesn't know about emails at all.
        foreach (['a@example.test', 'b@example.test', 'c@example.test'] as $email) {
            self::assertTrue(RateLimit::allowWindow($this->bucket, 3, 300) && RateLimit::allowForWindow($this->bucket, $email, 3, 300));
        }

        self::assertFalse(RateLimit::allowWindow($this->bucket, 3, 300), 'a fourth email from the same client');
    }

    public function testEachRecipientHasABudgetOfItsOwn(): void
    {
        self::assertTrue(RateLimit::allowForWindow($this->bucket, 'victim@example.test', 2, 300));
        self::assertTrue(RateLimit::allowForWindow($this->bucket, 'victim@example.test', 2, 300));
        self::assertFalse(RateLimit::allowForWindow($this->bucket, 'victim@example.test', 2, 300), 'however many clients ask');
        self::assertTrue(RateLimit::allowForWindow($this->bucket, 'someone-else@example.test', 2, 300));
    }

    public function testIpv6IsBudgetedPerSlash64(): void
    {
        self::assertSame(RateLimit::key('2001:db8:1:2::1'), RateLimit::key('2001:db8:1:2:ffff::9'));
        self::assertNotSame(RateLimit::key('2001:db8:1:2::1'), RateLimit::key('2001:db8:1:3::1'));
        self::assertSame('203.0.113.7', RateLimit::key('203.0.113.7'));
    }

    public function testAForwardedAddressIsUsedOnlyWhenGiven(): void
    {
        self::assertSame('203.0.113.9', RateLimit::key('10.0.0.1', '203.0.113.9'));
        self::assertSame('10.0.0.1', RateLimit::key('10.0.0.1', null));
    }
}
