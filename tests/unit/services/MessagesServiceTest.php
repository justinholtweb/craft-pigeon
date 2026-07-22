<?php

namespace justinholtweb\pigeontests\unit\services;

use justinholtweb\pigeon\enums\ParticipantRole;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\records\MessageRecord;
use justinholtweb\pigeon\records\ParticipantRecord;
use justinholtweb\pigeon\services\Messages;
use justinholtweb\pigeontests\unit\PigeonTestCase;
use RuntimeException;

class MessagesServiceTest extends PigeonTestCase
{
    public function testPostStoresAndNormalizesTheMessage(): void
    {
        $thread = $this->plugin()->threads->createSupportThread('Subject', 'guest@example.test', 'Guest');

        $message = $this->plugin()->messages->post($thread, [
            'body' => "  Hello there  ",
            'authorEmail' => ' Guest@Example.test ',
            'authorName' => 'Guest',
        ]);

        self::assertNotNull($message->id);
        self::assertSame('Hello there', $message->body, 'Body should be trimmed');
        self::assertSame('guest@example.test', $message->authorEmail, 'Author email should be lower-cased');
        self::assertFalse((bool)$message->isInternalNote);
        self::assertFalse((bool)$message->isSystem);
    }

    public function testPostRejectsAnEmptyMessage(): void
    {
        $thread = $this->plugin()->threads->createSupportThread('Subject', 'guest@example.test');

        $this->expectException(RuntimeException::class);
        $this->plugin()->messages->post($thread, ['body' => '   ']);
    }

    public function testSystemMessagesMayBeEmpty(): void
    {
        $thread = $this->plugin()->threads->createSupportThread('Subject', 'guest@example.test');

        $message = $this->plugin()->messages->post($thread, ['body' => '', 'isSystem' => true]);

        self::assertNotNull($message->id);
        self::assertTrue((bool)$message->isSystem);
    }

    public function testPostUpdatesThreadDenormalizedFields(): void
    {
        $thread = $this->plugin()->threads->createSupportThread('Subject', 'guest@example.test');
        $message = $this->plugin()->messages->post($thread, [
            'body' => 'Hi',
            'authorEmail' => 'guest@example.test',
        ]);

        $reloaded = $this->reloadThread($thread->id);
        self::assertSame($message->id, $reloaded->lastMessageId);
        self::assertNotNull($reloaded->lastMessageAt);
        self::assertNull($reloaded->lastMessageUserId, 'A guest message has no author user');
    }

    public function testGuestReplyMovesSupportThreadToPending(): void
    {
        $thread = $this->createGuestThread();
        $staff = $this->createStaffUser();

        // Staff replies → awaiting the customer.
        $this->plugin()->messages->post($thread, ['body' => 'Looking into it', 'authorUserId' => $staff->id]);
        self::assertSame(ThreadStatus::Open->value, $this->reloadThread($thread->id)->threadStatus);

        // Customer writes back → needs staff again.
        $thread = $this->reloadThread($thread->id);
        $this->plugin()->messages->post($thread, [
            'body' => 'Any news?',
            'authorEmail' => 'guest@example.test',
        ]);
        self::assertSame(ThreadStatus::Pending->value, $this->reloadThread($thread->id)->threadStatus);
    }

    public function testAdminUserRepliesCountAsStaff(): void
    {
        $thread = $this->createGuestThread();
        $admin = $this->createUser('admin@example.test', true);

        $this->plugin()->messages->post($thread, ['body' => 'On it', 'authorUserId' => $admin->id]);

        self::assertSame(ThreadStatus::Open->value, $this->reloadThread($thread->id)->threadStatus);
    }

    public function testClosedSupportThreadStatusIsNotChangedByPosting(): void
    {
        $thread = $this->createGuestThread();
        $this->plugin()->threads->setStatus($thread, ThreadStatus::Closed);
        $thread = $this->reloadThread($thread->id);

        $this->plugin()->messages->post($thread, [
            'body' => 'One more thing',
            'authorEmail' => 'guest@example.test',
        ]);

        self::assertSame(
            ThreadStatus::Closed->value,
            $this->reloadThread($thread->id)->threadStatus,
            'Reopening is the caller’s decision, not a side effect of posting',
        );
    }

    public function testDirectThreadStatusIsUntouched(): void
    {
        $a = $this->createUser('direct-a@example.test');
        $b = $this->createUser('direct-b@example.test');
        $thread = $this->plugin()->threads->createDirectThread('Chat', $a->id, [$b->id]);

        $this->plugin()->messages->post($thread, ['body' => 'yo', 'authorUserId' => $a->id]);

        self::assertSame(ThreadStatus::Open->value, $this->reloadThread($thread->id)->threadStatus);
    }

    public function testInternalNotesDoNotTouchTheThread(): void
    {
        $thread = $this->createGuestThread();
        $before = $this->reloadThread($thread->id);
        $staff = $this->createStaffUser();

        $note = $this->plugin()->messages->post($before, [
            'body' => 'Customer is a VIP',
            'authorUserId' => $staff->id,
            'isInternalNote' => true,
        ]);

        $after = $this->reloadThread($thread->id);
        self::assertTrue((bool)$note->isInternalNote);
        self::assertSame($before->lastMessageId, $after->lastMessageId);
        self::assertSame($before->threadStatus, $after->threadStatus);
    }

    public function testGetForThreadExcludesInternalNotesByDefault(): void
    {
        $thread = $this->createGuestThread();
        $staff = $this->createStaffUser();

        $this->plugin()->messages->post($thread, [
            'body' => 'internal',
            'authorUserId' => $staff->id,
            'isInternalNote' => true,
        ]);

        $visible = $this->plugin()->messages->getForThread($thread->id);
        $all = $this->plugin()->messages->getForThread($thread->id, true);

        self::assertCount(1, $visible);
        self::assertCount(2, $all);
    }

    public function testGetForThreadReturnsOldestFirst(): void
    {
        $thread = $this->createGuestThread(body: 'first');
        $this->plugin()->messages->post($thread, ['body' => 'second', 'authorEmail' => 'guest@example.test']);
        $this->plugin()->messages->post($thread, ['body' => 'third', 'authorEmail' => 'guest@example.test']);

        $bodies = array_map(
            static fn(MessageRecord $m) => $m->body,
            $this->plugin()->messages->getForThread($thread->id),
        );

        self::assertSame(['first', 'second', 'third'], $bodies);
    }

    public function testPostRegistersAnUnknownAuthorAsParticipant(): void
    {
        $thread = $this->createGuestThread();
        $staff = $this->createStaffUser();

        $this->plugin()->messages->post($thread, ['body' => 'Hi', 'authorUserId' => $staff->id]);

        $participant = ParticipantRecord::findOne(['threadId' => $thread->id, 'userId' => $staff->id]);
        self::assertNotNull($participant);
        self::assertSame(
            ParticipantRole::Admin->value,
            $participant->role,
            'Staff replying on a support thread should be tracked as an admin',
        );
    }

    public function testNonStaffUserOnSupportThreadIsAPlainParticipant(): void
    {
        $thread = $this->createGuestThread();
        $customer = $this->createUser('plain@example.test');

        $this->plugin()->messages->post($thread, ['body' => 'Me too', 'authorUserId' => $customer->id]);

        $participant = ParticipantRecord::findOne(['threadId' => $thread->id, 'userId' => $customer->id]);
        self::assertSame(ParticipantRole::Participant->value, $participant->role);
    }

    public function testPostMarksTheMessageReadForItsAuthor(): void
    {
        $a = $this->createUser('reader-a@example.test');
        $b = $this->createUser('reader-b@example.test');
        $thread = $this->plugin()->threads->createDirectThread('Chat', $a->id, [$b->id]);

        $message = $this->plugin()->messages->post($thread, ['body' => 'hello', 'authorUserId' => $a->id]);

        $author = $this->plugin()->participants->getForUser($thread->id, $a->id);
        $other = $this->plugin()->participants->getForUser($thread->id, $b->id);

        self::assertSame($message->id, $author->lastReadMessageId);
        self::assertNull($other->lastReadMessageId, 'The recipient has not read it yet');
    }

    public function testAfterPostMessageEventIsTriggered(): void
    {
        $thread = $this->createGuestThread();
        $fired = 0;

        $this->plugin()->messages->on(
            Messages::EVENT_AFTER_POST_MESSAGE,
            static function() use (&$fired): void {
                $fired++;
            },
        );

        $this->plugin()->messages->post($thread, ['body' => 'ping', 'authorEmail' => 'guest@example.test']);

        self::assertSame(1, $fired);
    }
}
