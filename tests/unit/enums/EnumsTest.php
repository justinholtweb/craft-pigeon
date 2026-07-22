<?php

namespace justinholtweb\pigeontests\unit\enums;

use Codeception\Test\Unit;
use justinholtweb\pigeon\enums\ParticipantRole;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\enums\ThreadType;

class EnumsTest extends Unit
{
    public function testThreadStatusValues(): void
    {
        self::assertSame(['open', 'pending', 'closed'], ThreadStatus::values());
    }

    public function testThreadStatusLabelsAndColors(): void
    {
        self::assertSame('Open', ThreadStatus::Open->label());
        self::assertSame('Pending', ThreadStatus::Pending->label());
        self::assertSame('Closed', ThreadStatus::Closed->label());

        self::assertSame('green', ThreadStatus::Open->color());
        self::assertSame('orange', ThreadStatus::Pending->color());
        self::assertSame('grey', ThreadStatus::Closed->color());
    }

    public function testThreadStatusTryFromRejectsUnknown(): void
    {
        self::assertNull(ThreadStatus::tryFrom('archived'));
        self::assertSame(ThreadStatus::Closed, ThreadStatus::tryFrom('closed'));
    }

    public function testThreadTypeValues(): void
    {
        self::assertSame(['support', 'direct'], ThreadType::values());
        self::assertSame('Support', ThreadType::Support->label());
        self::assertSame('Direct', ThreadType::Direct->label());
        self::assertNull(ThreadType::tryFrom('broadcast'));
    }

    public function testParticipantRoleValues(): void
    {
        self::assertSame(['owner', 'admin', 'guest', 'participant'], ParticipantRole::values());
        self::assertSame('Owner', ParticipantRole::Owner->label());
        self::assertSame('Admin', ParticipantRole::Admin->label());
        self::assertSame('Guest', ParticipantRole::Guest->label());
        self::assertSame('Participant', ParticipantRole::Participant->label());
    }

    /**
     * Every enum case must have a label — guards against a case being added
     * without extending the match() arms (which would throw at runtime).
     */
    public function testEveryCaseHasALabel(): void
    {
        foreach ([ThreadStatus::cases(), ThreadType::cases(), ParticipantRole::cases()] as $cases) {
            foreach ($cases as $case) {
                self::assertNotSame('', $case->label());
            }
        }
    }
}
