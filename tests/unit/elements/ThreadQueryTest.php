<?php

namespace justinholtweb\pigeontests\unit\elements;

use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\enums\ThreadType;
use justinholtweb\pigeontests\unit\PigeonTestCase;

class ThreadQueryTest extends PigeonTestCase
{
    public function testQuerySelectsTheThreadColumns(): void
    {
        $created = $this->createGuestThread('columns@example.test', 'Columns');

        $thread = Thread::find()->id($created->id)->status(null)->one();

        self::assertNotNull($thread);
        self::assertSame($created->id, $thread->id);
        self::assertSame('Columns', $thread->title);
        self::assertSame(ThreadType::Support->value, $thread->type);
        self::assertSame(ThreadStatus::Pending->value, $thread->threadStatus);
        self::assertSame('columns@example.test', $thread->starterEmail);
        self::assertNotNull($thread->lastMessageId);
        self::assertNotNull($thread->lastMessageAt);
    }

    public function testFilterByType(): void
    {
        $a = $this->createUser('q-type-a@example.test');
        $b = $this->createUser('q-type-b@example.test');

        $support = $this->createGuestThread('q-type@example.test');
        $direct = $this->plugin()->threads->createDirectThread('Direct', $a->id, [$b->id]);

        $supportIds = $this->ids(Thread::find()->type(ThreadType::Support->value)->status(null));
        $directIds = $this->ids(Thread::find()->type(ThreadType::Direct->value)->status(null));

        self::assertContains($support->id, $supportIds);
        self::assertNotContains($direct->id, $supportIds);
        self::assertContains($direct->id, $directIds);
    }

    public function testFilterByThreadStatusIncludingArrays(): void
    {
        $pending = $this->createGuestThread('q-pending@example.test');
        $closed = $this->createGuestThread('q-closed@example.test');
        $this->plugin()->threads->setStatus($this->reloadThread($closed->id), ThreadStatus::Closed);

        $pendingIds = $this->ids(Thread::find()->threadStatus(ThreadStatus::Pending->value)->status(null));
        self::assertContains($pending->id, $pendingIds);
        self::assertNotContains($closed->id, $pendingIds);

        $bothIds = $this->ids(Thread::find()->threadStatus(['pending', 'closed'])->status(null));
        self::assertContains($pending->id, $bothIds);
        self::assertContains($closed->id, $bothIds);
    }

    public function testFilterByAssigneeAndStarter(): void
    {
        $staff = $this->createStaffUser('q-staff@example.test');
        $customer = $this->createUser('q-customer@example.test');

        $assigned = $this->createGuestThread('q-assigned@example.test');
        $this->plugin()->threads->assign($this->reloadThread($assigned->id), $staff->id);

        $started = $this->plugin()->threads->createSupportThread('Mine', $customer->email, (string)$customer, $customer->id);
        $other = $this->createGuestThread('q-other@example.test');

        $assignedIds = $this->ids(Thread::find()->assigneeId($staff->id)->status(null));
        self::assertSame([$assigned->id], $assignedIds);

        $startedIds = $this->ids(Thread::find()->starterUserId($customer->id)->status(null));
        self::assertSame([$started->id], $startedIds);
        self::assertNotContains($other->id, $startedIds);
    }

    public function testForUserOnlyMatchesActiveParticipants(): void
    {
        $member = $this->createUser('q-member@example.test');
        $other = $this->createUser('q-member-other@example.test');

        $mine = $this->plugin()->threads->createDirectThread('Mine', $member->id, [$other->id]);
        $theirs = $this->plugin()->threads->createDirectThread('Theirs', $other->id, []);

        $ids = $this->ids(Thread::find()->forUser($member->id)->status(null));
        self::assertContains($mine->id, $ids);
        self::assertNotContains($theirs->id, $ids);

        // Leaving the thread removes it from the user's scope.
        $participant = $this->plugin()->participants->getForUser($mine->id, $member->id);
        $participant->leftAt = \craft\helpers\Db::prepareDateForDb(new \DateTime());
        $participant->save(false);

        self::assertNotContains($mine->id, $this->ids(Thread::find()->forUser($member->id)->status(null)));
    }

    public function testForUserAcceptsAUserElement(): void
    {
        $member = $this->createUser('q-user-element@example.test');
        $other = $this->createUser('q-user-element-other@example.test');
        $thread = $this->plugin()->threads->createDirectThread('Mine', $member->id, [$other->id]);

        self::assertContains($thread->id, $this->ids(Thread::find()->forUser($member)->status(null)));
    }

    public function testUnreadScope(): void
    {
        $reader = $this->createUser('q-unread-reader@example.test');
        $writer = $this->createUser('q-unread-writer@example.test');
        $thread = $this->plugin()->threads->createDirectThread('Unread', $writer->id, [$reader->id]);

        // No messages yet → nothing unread.
        self::assertSame([], $this->ids(Thread::find()->forUser($reader->id)->unread()->status(null)));

        $this->plugin()->messages->post($thread, ['body' => 'hi', 'authorUserId' => $writer->id]);

        self::assertSame([$thread->id], $this->ids(Thread::find()->forUser($reader->id)->unread()->status(null)));
        self::assertSame(
            [],
            $this->ids(Thread::find()->forUser($writer->id)->unread()->status(null)),
            'The sender never has their own message unread',
        );

        $participant = $this->plugin()->participants->getForUser($thread->id, $reader->id);
        $this->plugin()->participants->markRead($participant);

        self::assertSame([], $this->ids(Thread::find()->forUser($reader->id)->unread()->status(null)));
    }

    public function testUnreadBecomesTrueAgainAfterANewMessage(): void
    {
        $reader = $this->createUser('q-unread2-reader@example.test');
        $writer = $this->createUser('q-unread2-writer@example.test');
        $thread = $this->plugin()->threads->createDirectThread('Unread', $writer->id, [$reader->id]);

        $this->plugin()->messages->post($thread, ['body' => 'one', 'authorUserId' => $writer->id]);
        $this->plugin()->participants->markRead($this->plugin()->participants->getForUser($thread->id, $reader->id));
        self::assertSame([], $this->ids(Thread::find()->forUser($reader->id)->unread()->status(null)));

        $this->plugin()->messages->post($this->reloadThread($thread->id), ['body' => 'two', 'authorUserId' => $writer->id]);

        self::assertSame([$thread->id], $this->ids(Thread::find()->forUser($reader->id)->unread()->status(null)));
    }

    public function testStatusConditionMapsToThreadStatus(): void
    {
        $pending = $this->createGuestThread('q-status-pending@example.test');
        $closed = $this->createGuestThread('q-status-closed@example.test');
        $this->plugin()->threads->setStatus($this->reloadThread($closed->id), ThreadStatus::Closed);

        $pendingIds = $this->ids(Thread::find()->status(ThreadStatus::Pending->value));
        self::assertContains($pending->id, $pendingIds);
        self::assertNotContains($closed->id, $pendingIds);

        $closedIds = $this->ids(Thread::find()->status(ThreadStatus::Closed->value));
        self::assertContains($closed->id, $closedIds);
        self::assertNotContains($pending->id, $closedIds);
    }

    public function testCountAndExistsAgreeWithAll(): void
    {
        $this->createGuestThread('q-count@example.test');

        $query = Thread::find()->type(ThreadType::Support->value)->status(null);

        self::assertTrue($query->exists());
        self::assertSame(count($query->all()), (int)$query->count());
    }

    /**
     * @return int[]
     */
    private function ids(\justinholtweb\pigeon\elements\db\ThreadQuery $query): array
    {
        return array_map(static fn(Thread $thread) => $thread->id, $query->all());
    }
}
