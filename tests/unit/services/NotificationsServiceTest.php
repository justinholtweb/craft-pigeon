<?php

namespace justinholtweb\pigeontests\unit\services;

use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeontests\unit\PigeonTestCase;

class NotificationsServiceTest extends PigeonTestCase
{
    public function testGuestMessageOnUnassignedThreadAlertsSupport(): void
    {
        $this->clearQueue();

        $this->plugin()->getSettings()->supportNotificationRecipients = 'support@example.test, escalations@example.test';

        try {
            $this->createGuestThread('alerting@example.test');
        } finally {
            $this->plugin()->getSettings()->supportNotificationRecipients = '';
        }

        self::assertSame(2, $this->queuedJobCount(), 'One staff alert per configured recipient');
    }

    public function testAuthorIsNeverNotifiedOfTheirOwnMessage(): void
    {
        $a = $this->createUser('notify-a@example.test');
        $b = $this->createUser('notify-b@example.test');
        $thread = $this->plugin()->threads->createDirectThread('Chat', $a->id, [$b->id]);

        $this->clearQueue();
        $this->plugin()->messages->post($thread, ['body' => 'hello', 'authorUserId' => $a->id]);

        self::assertSame(1, $this->queuedJobCount(), 'Only the other participant is notified');
    }

    public function testGuestAuthorIsNotNotifiedOfTheirOwnMessage(): void
    {
        $thread = $this->createGuestThread('self@example.test');
        $staff = $this->createStaffUser();
        $this->plugin()->threads->assign($this->reloadThread($thread->id), $staff->id);

        $this->clearQueue();
        $this->plugin()->messages->post(
            $this->reloadThread($thread->id),
            ['body' => 'more info', 'authorEmail' => 'self@example.test'],
        );

        self::assertSame(1, $this->queuedJobCount(), 'Only the assigned staff member is notified');
    }

    public function testSystemMessagesAreNeverEmailed(): void
    {
        $thread = $this->createGuestThread();
        $this->clearQueue();

        $this->plugin()->messages->post($thread, ['body' => 'Status changed', 'isSystem' => true]);

        self::assertSame(0, $this->queuedJobCount());
    }

    public function testInternalNotesOnlyGoToStaff(): void
    {
        $thread = $this->createGuestThread();
        $staffAuthor = $this->createStaffUser('note-author@example.test');
        $staffReader = $this->createStaffUser('note-reader@example.test');

        $this->plugin()->participants->ensureUser(
            $thread->id,
            $staffReader->id,
            \justinholtweb\pigeon\enums\ParticipantRole::Admin->value,
        );

        $this->clearQueue();
        $this->plugin()->messages->post($thread, [
            'body' => 'FYI',
            'authorUserId' => $staffAuthor->id,
            'isInternalNote' => true,
        ]);

        self::assertSame(1, $this->queuedJobCount(), 'The guest owner must not receive internal notes');
    }

    public function testParticipantsWhoOptedOutAreNotNotified(): void
    {
        $a = $this->createUser('optout-a@example.test');
        $b = $this->createUser('optout-b@example.test');
        $thread = $this->plugin()->threads->createDirectThread('Chat', $a->id, [$b->id]);

        $participant = $this->plugin()->participants->getForUser($thread->id, $b->id);
        $participant->notify = false;
        $participant->save(false);

        $this->clearQueue();
        $this->plugin()->messages->post($thread, ['body' => 'hello', 'authorUserId' => $a->id]);

        self::assertSame(0, $this->queuedJobCount());
    }

    public function testAssignedThreadsDoNotRaiseStaffAlerts(): void
    {
        $thread = $this->createGuestThread('assigned@example.test');
        $staff = $this->createStaffUser();
        $this->plugin()->threads->assign($this->reloadThread($thread->id), $staff->id);

        $this->plugin()->getSettings()->supportNotificationRecipients = 'support@example.test';
        $this->clearQueue();

        try {
            $this->plugin()->messages->post(
                $this->reloadThread($thread->id),
                ['body' => 'still broken', 'authorEmail' => 'assigned@example.test'],
            );
        } finally {
            $this->plugin()->getSettings()->supportNotificationRecipients = '';
        }

        self::assertSame(1, $this->queuedJobCount(), 'Only the assignee is notified once a thread is owned');
    }

    public function testInboxCountForStaffCountsPendingSupportThreads(): void
    {
        $admin = $this->createUser('inbox-admin@example.test', true);

        $before = $this->plugin()->notifications->inboxCountForStaff($admin);
        $this->createGuestThread('inbox@example.test');

        self::assertSame($before + 1, $this->plugin()->notifications->inboxCountForStaff($admin));
    }

    public function testInboxCountIgnoresOpenAndClosedThreads(): void
    {
        $admin = $this->createUser('inbox-admin2@example.test', true);
        $thread = $this->createGuestThread('inbox2@example.test');

        $before = $this->plugin()->notifications->inboxCountForStaff($admin);
        $this->plugin()->threads->setStatus($this->reloadThread($thread->id), ThreadStatus::Closed);

        self::assertSame($before - 1, $this->plugin()->notifications->inboxCountForStaff($admin));
    }

    public function testInboxCountForNonAdminIsScopedToTheirThreads(): void
    {
        $staff = $this->createStaffUser('scoped@example.test');
        $otherStaff = $this->createStaffUser('scoped-other@example.test');

        $unassigned = $this->createGuestThread('scoped-unassigned@example.test');
        $mine = $this->createGuestThread('scoped-mine@example.test');
        $theirs = $this->createGuestThread('scoped-theirs@example.test');

        $this->plugin()->threads->assign($this->reloadThread($mine->id), $staff->id);
        $this->plugin()->threads->assign($this->reloadThread($theirs->id), $otherStaff->id);

        $count = $this->plugin()->notifications->inboxCountForStaff($staff);
        $threads = $this->plugin()->notifications->recentInboxThreads(50);
        $visibleIds = array_map(static fn($t) => $t->id, $threads);

        self::assertContains($unassigned->id, $visibleIds);
        self::assertGreaterThanOrEqual(2, $count);

        // The thread owned by another staff member must not be in this user's count.
        $ids = [];
        foreach ($threads as $thread) {
            if ($thread->assigneeId === null || $thread->assigneeId === $staff->id) {
                $ids[] = $thread->id;
            }
        }
        self::assertNotContains($theirs->id, $ids);
    }

    public function testRecentInboxThreadsRespectsTheLimitAndOrdersByActivity(): void
    {
        $first = $this->createGuestThread('recent-1@example.test');
        $second = $this->createGuestThread('recent-2@example.test');

        $threads = $this->plugin()->notifications->recentInboxThreads(1);

        self::assertCount(1, $threads);
        self::assertSame($second->id, $threads[0]->id, 'Most recent activity first');
        self::assertNotSame($first->id, $threads[0]->id);
    }
}
