<?php

namespace justinholtweb\pigeontests\unit\models;

use Codeception\Test\Unit;
use justinholtweb\pigeon\models\Settings;

class SettingsTest extends Unit
{
    public function testDefaults(): void
    {
        $settings = new Settings();

        self::assertTrue($settings->allowGuestThreads);
        self::assertTrue($settings->allowUserThreads);
        self::assertTrue($settings->enableHoneypot);
        self::assertSame('pigeon_hp', $settings->honeypotField);
        self::assertSame(5, $settings->maxAttachmentsPerMessage);
        self::assertSame(10, $settings->maxAttachmentSizeMb);
        self::assertSame(30, $settings->guestTokenLifetimeDays);
        self::assertSame(10, $settings->rateLimitMaxMessages);
        self::assertSame(300, $settings->rateLimitWindowSeconds);
        self::assertContains('pdf', $settings->allowedAttachmentExtensions);
        self::assertTrue($settings->validate());
    }

    public function testSupportRecipientsFromDelimitedString(): void
    {
        $settings = new Settings();
        $settings->supportNotificationRecipients = "a@example.test, b@example.test;\n c@example.test";

        self::assertSame(
            ['a@example.test', 'b@example.test', 'c@example.test'],
            $settings->getSupportRecipients(),
        );
    }

    public function testSupportRecipientsFromArrayAreTrimmedAndCompacted(): void
    {
        $settings = new Settings();
        $settings->supportNotificationRecipients = [' a@example.test ', '', 'b@example.test'];

        self::assertSame(['a@example.test', 'b@example.test'], $settings->getSupportRecipients());
    }

    public function testSupportRecipientsEmptyByDefault(): void
    {
        self::assertSame([], (new Settings())->getSupportRecipients());
    }

    public function testInvalidFromEmailFailsValidation(): void
    {
        $settings = new Settings();
        $settings->fromEmail = 'not-an-email';

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('fromEmail', $settings->getErrors());
    }

    public function testEmptyFromEmailPassesValidation(): void
    {
        $settings = new Settings();
        $settings->fromEmail = '';

        self::assertTrue($settings->validate());
    }

    /**
     * @dataProvider outOfRangeIntProvider
     */
    public function testOutOfRangeIntegersFailValidation(string $attribute, int $value): void
    {
        $settings = new Settings();
        $settings->$attribute = $value;

        self::assertFalse($settings->validate(), "$attribute = $value should be invalid");
        self::assertArrayHasKey($attribute, $settings->getErrors());
    }

    public static function outOfRangeIntProvider(): array
    {
        return [
            'too many attachments' => ['maxAttachmentsPerMessage', 21],
            'negative attachments' => ['maxAttachmentsPerMessage', -1],
            'attachment size zero' => ['maxAttachmentSizeMb', 0],
            'attachment size huge' => ['maxAttachmentSizeMb', 201],
            'token lifetime zero' => ['guestTokenLifetimeDays', 0],
            'token lifetime too long' => ['guestTokenLifetimeDays', 366],
            'rate limit zero' => ['rateLimitMaxMessages', 0],
            'rate window zero' => ['rateLimitWindowSeconds', 0],
        ];
    }
}
