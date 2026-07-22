<?php

namespace justinholtweb\pigeontests\unit\elements;

use Craft;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\enums\ThreadType;
use justinholtweb\pigeon\records\ThreadRecord;
use justinholtweb\pigeontests\unit\PigeonTestCase;

class ThreadElementTest extends PigeonTestCase
{
    public function testSavingWritesTheBackingRecord(): void
    {
        $thread = new Thread();
        $thread->title = 'Record sync';
        $thread->type = ThreadType::Support->value;
        $thread->threadStatus = ThreadStatus::Pending->value;
        $thread->starterEmail = 'record@example.test';

        self::assertTrue(Craft::$app->getElements()->saveElement($thread));

        $record = ThreadRecord::findOne($thread->id);
        self::assertNotNull($record);
        self::assertSame(ThreadType::Support->value, $record->type);
        self::assertSame(ThreadStatus::Pending->value, $record->threadStatus);
        self::assertSame('record@example.test', $record->starterEmail);
    }

    public function testUpdatingReusesTheSameRecord(): void
    {
        $thread = $this->createGuestThread();
        $thread->title = 'Renamed';
        $thread->threadStatus = ThreadStatus::Open->value;

        self::assertTrue(Craft::$app->getElements()->saveElement($thread));

        self::assertCount(1, ThreadRecord::findAll(['id' => $thread->id]));
        self::assertSame(ThreadStatus::Open->value, ThreadRecord::findOne($thread->id)->threadStatus);
    }

    public function testInvalidTypeIsRejected(): void
    {
        $thread = new Thread();
        $thread->title = 'Bad type';
        $thread->type = 'broadcast';

        self::assertFalse(Craft::$app->getElements()->saveElement($thread));
        self::assertArrayHasKey('type', $thread->getErrors());
    }

    public function testInvalidStatusIsRejected(): void
    {
        $thread = new Thread();
        $thread->title = 'Bad status';
        $thread->threadStatus = 'archived';

        self::assertFalse(Craft::$app->getElements()->saveElement($thread));
        self::assertArrayHasKey('threadStatus', $thread->getErrors());
    }

    public function testInvalidStarterEmailIsRejected(): void
    {
        $thread = new Thread();
        $thread->title = 'Bad email';
        $thread->starterEmail = 'nope';

        self::assertFalse(Craft::$app->getElements()->saveElement($thread));
        self::assertArrayHasKey('starterEmail', $thread->getErrors());
    }

    public function testGetStatusReflectsThreadStatus(): void
    {
        $thread = $this->createGuestThread();

        self::assertSame(ThreadStatus::Pending->value, $thread->getStatus());

        $this->plugin()->threads->setStatus($thread, ThreadStatus::Closed);
        self::assertSame(ThreadStatus::Closed->value, $this->reloadThread($thread->id)->getStatus());
    }

    public function testStatusesCoverEveryThreadStatus(): void
    {
        self::assertSame(ThreadStatus::values(), array_keys(Thread::statuses()));
    }

    public function testGetAssigneeAndStarter(): void
    {
        $staff = $this->createStaffUser();
        $customer = $this->createUser('starter-user@example.test');

        $thread = $this->plugin()->threads->createSupportThread('Hi', $customer->email, (string)$customer, $customer->id);
        $this->plugin()->threads->assign($thread, $staff->id);

        $reloaded = $this->reloadThread($thread->id);

        self::assertSame($staff->id, $reloaded->getAssignee()?->id);
        self::assertSame($customer->id, $reloaded->getStarter()?->id);
    }

    public function testGetAssigneeAndStarterAreNullWhenUnset(): void
    {
        $thread = $this->createGuestThread();

        self::assertNull($thread->getAssignee());
        self::assertNull($thread->getStarter());
    }

    public function testCpEditUrlPointsAtTheThread(): void
    {
        $thread = $this->createGuestThread();

        self::assertStringContainsString("pigeon/threads/{$thread->id}", (string)$thread->getCpEditUrl());
    }

    public function testPermissionsGateViewSaveAndDelete(): void
    {
        $thread = $this->createGuestThread();
        $staff = $this->createStaffUser('perm-staff@example.test');
        $stranger = $this->createUser('perm-stranger@example.test');

        self::assertTrue($thread->canView($staff));
        self::assertTrue($thread->canSave($staff));
        self::assertTrue($thread->canDelete($staff));

        self::assertFalse($thread->canView($stranger));
        self::assertFalse($thread->canSave($stranger));
        self::assertFalse($thread->canDelete($stranger));
    }

    public function testReadOnlyAccessCanViewButNotSave(): void
    {
        $thread = $this->createGuestThread();
        $viewer = $this->createUser('perm-viewer@example.test', false, ['pigeon:accessplugin']);

        self::assertTrue($thread->canView($viewer));
        self::assertFalse($thread->canSave($viewer));
    }

    public function testDeletingAThreadCascadesToItsMessages(): void
    {
        $thread = $this->createGuestThread();
        self::assertNotEmpty($this->messagesFor($thread->id));

        Craft::$app->getElements()->deleteElement($thread, true);

        self::assertNull(ThreadRecord::findOne($thread->id));
        self::assertSame([], $this->messagesFor($thread->id));
    }
}
