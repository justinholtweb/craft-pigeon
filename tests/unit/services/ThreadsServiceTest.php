<?php

namespace justinholtweb\pigeontests\unit\services;

use justinholtweb\pigeon\enums\ParticipantRole;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\enums\ThreadType;
use justinholtweb\pigeon\records\ParticipantRecord;
use justinholtweb\pigeontests\unit\PigeonTestCase;

class ThreadsServiceTest extends PigeonTestCase
{
    public function testCreateSupportThreadForGuest(): void
    {
        $thread = $this->plugin()->threads->createSupportThread('Broken widget', 'Guest@Example.test', 'Guest Person');

        self::assertNotNull($thread->id);
        self::assertSame('Broken widget', $thread->title);
        self::assertSame(ThreadType::Support->value, $thread->type);
        self::assertSame(ThreadStatus::Pending->value, $thread->threadStatus);
        self::assertSame('guest@example.test', $thread->starterEmail, 'Starter email should be normalized');
        self::assertNull($thread->starterUserId);

        $participants = $this->plugin()->participants->getForThread($thread->id);
        self::assertCount(1, $participants);
        self::assertSame(ParticipantRole::Owner->value, $participants[0]->role);
        self::assertSame('guest@example.test', $participants[0]->email);
        self::assertNull($participants[0]->userId);
    }

    public function testCreateSupportThreadForLoggedInUser(): void
    {
        $user = $this->createUser('customer@example.test');
        $thread = $this->plugin()->threads->createSupportThread('Question', $user->email, (string)$user, $user->id);

        self::assertSame($user->id, $thread->starterUserId);

        $participants = $this->plugin()->participants->getForThread($thread->id);
        self::assertCount(1, $participants);
        self::assertSame($user->id, $participants[0]->userId);
        self::assertSame(ParticipantRole::Owner->value, $participants[0]->role);
    }

    public function testCreateSupportThreadFallsBackToDefaultTitle(): void
    {
        $thread = $this->plugin()->threads->createSupportThread('', 'guest@example.test');

        self::assertSame('Support request', $thread->title);
    }

    public function testCreateDirectThread(): void
    {
        $starter = $this->createUser('starter@example.test');
        $recipientA = $this->createUser('recipient-a@example.test');
        $recipientB = $this->createUser('recipient-b@example.test');

        $thread = $this->plugin()->threads->createDirectThread(
            'Lunch?',
            $starter->id,
            [$recipientA->id, $recipientB->id, $recipientA->id, $starter->id],
        );

        self::assertSame(ThreadType::Direct->value, $thread->type);
        self::assertSame(ThreadStatus::Open->value, $thread->threadStatus);

        $participants = $this->plugin()->participants->getForThread($thread->id);
        self::assertCount(3, $participants, 'Duplicates and the starter should not be added twice');

        $byUser = [];
        foreach ($participants as $participant) {
            $byUser[$participant->userId] = $participant->role;
        }

        self::assertSame(ParticipantRole::Owner->value, $byUser[$starter->id]);
        self::assertSame(ParticipantRole::Participant->value, $byUser[$recipientA->id]);
        self::assertSame(ParticipantRole::Participant->value, $byUser[$recipientB->id]);
    }

    public function testCreateDirectThreadFallsBackToDefaultTitle(): void
    {
        $starter = $this->createUser('starter2@example.test');
        $thread = $this->plugin()->threads->createDirectThread('', $starter->id, []);

        self::assertSame('New message', $thread->title);
    }

    public function testGetByIdReturnsThreadRegardlessOfStatus(): void
    {
        $thread = $this->createGuestThread();
        $this->plugin()->threads->setStatus($thread, ThreadStatus::Closed);

        $found = $this->plugin()->threads->getById($thread->id);
        self::assertNotNull($found);
        self::assertSame($thread->id, $found->id);
        self::assertSame(ThreadStatus::Closed->value, $found->threadStatus);
    }

    public function testGetByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->plugin()->threads->getById(999999));
    }

    public function testAssignAddsStaffParticipant(): void
    {
        $thread = $this->createGuestThread();
        $staff = $this->createStaffUser();

        self::assertTrue($this->plugin()->threads->assign($thread, $staff->id));

        $reloaded = $this->reloadThread($thread->id);
        self::assertSame($staff->id, $reloaded->assigneeId);

        $participant = ParticipantRecord::findOne(['threadId' => $thread->id, 'userId' => $staff->id]);
        self::assertNotNull($participant);
        self::assertSame(ParticipantRole::Admin->value, $participant->role);
    }

    public function testUnassignClearsTheAssignee(): void
    {
        $thread = $this->createGuestThread();
        $staff = $this->createStaffUser();

        $this->plugin()->threads->assign($thread, $staff->id);
        $this->plugin()->threads->assign($thread, null);

        self::assertNull($this->reloadThread($thread->id)->assigneeId);
    }

    public function testSetStatusStampsClosedAt(): void
    {
        $thread = $this->createGuestThread();

        self::assertTrue($this->plugin()->threads->setStatus($thread, ThreadStatus::Closed));
        $closed = $this->reloadThread($thread->id);
        self::assertSame(ThreadStatus::Closed->value, $closed->threadStatus);
        self::assertNotNull($closed->closedAt);

        self::assertTrue($this->plugin()->threads->setStatus($closed, ThreadStatus::Open));
        $reopened = $this->reloadThread($thread->id);
        self::assertSame(ThreadStatus::Open->value, $reopened->threadStatus);
        self::assertNull($reopened->closedAt, 'Reopening should clear closedAt');
    }

    public function testGetThreadsForUserOnlyReturnsParticipatingThreads(): void
    {
        $user = $this->createUser('member@example.test');
        $other = $this->createUser('other@example.test');

        $mine = $this->plugin()->threads->createDirectThread('Mine', $user->id, [$other->id]);
        $theirs = $this->plugin()->threads->createDirectThread('Theirs', $other->id, []);

        $ids = array_map(static fn($t) => $t->id, $this->plugin()->threads->getThreadsForUser($user));

        self::assertContains($mine->id, $ids);
        self::assertNotContains($theirs->id, $ids);
    }

    public function testGetThreadsForUserCanFilterByType(): void
    {
        $user = $this->createUser('filter@example.test');
        $other = $this->createUser('filter-other@example.test');

        $direct = $this->plugin()->threads->createDirectThread('Direct', $user->id, [$other->id]);
        $support = $this->plugin()->threads->createSupportThread('Support', $user->email, (string)$user, $user->id);

        $directIds = array_map(
            static fn($t) => $t->id,
            $this->plugin()->threads->getThreadsForUser($user, ThreadType::Direct->value),
        );

        self::assertSame([$direct->id], $directIds);

        $supportIds = array_map(
            static fn($t) => $t->id,
            $this->plugin()->threads->getThreadsForUser($user, ThreadType::Support->value),
        );

        self::assertSame([$support->id], $supportIds);
    }

    public function testGetThreadsForUserOrdersByMostRecentActivity(): void
    {
        $user = $this->createUser('ordering@example.test');
        $other = $this->createUser('ordering-other@example.test');

        $older = $this->plugin()->threads->createDirectThread('Older', $user->id, [$other->id]);
        $newer = $this->plugin()->threads->createDirectThread('Newer', $user->id, [$other->id]);

        $this->plugin()->messages->post($older, ['body' => 'first', 'authorUserId' => $other->id]);
        $this->plugin()->messages->post($newer, ['body' => 'second', 'authorUserId' => $other->id]);

        $ids = array_map(static fn($t) => $t->id, $this->plugin()->threads->getThreadsForUser($user));

        self::assertSame([$newer->id, $older->id], array_slice($ids, 0, 2));
    }
}
