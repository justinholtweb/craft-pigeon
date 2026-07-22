<?php

namespace justinholtweb\pigeontests\unit\variables;

use Craft;
use craft\elements\User;
use justinholtweb\pigeon\enums\ThreadType;
use justinholtweb\pigeon\variables\PigeonVariable;
use justinholtweb\pigeontests\unit\PigeonTestCase;

class PigeonVariableTest extends PigeonTestCase
{
    private PigeonVariable $variable;

    protected function _before(): void
    {
        parent::_before();
        $this->variable = new PigeonVariable();
    }

    protected function _after(): void
    {
        Craft::$app->getUser()->setIdentity(null);
        parent::_after();
    }

    private function login(User $user): void
    {
        Craft::$app->getUser()->setIdentity($user);
    }

    public function testEverythingIsEmptyForAnonymousVisitors(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        self::assertSame([], $this->variable->threads());
        self::assertNull($this->variable->thread(1));
        self::assertSame(0, $this->variable->unreadCount());
        self::assertFalse($this->variable->isUnread(1));
        self::assertFalse($this->variable->canStartUserThread());
    }

    public function testThreadsReturnsOnlyTheUsersThreads(): void
    {
        $user = $this->createUser('var-user@example.test');
        $other = $this->createUser('var-other@example.test');
        $mine = $this->plugin()->threads->createDirectThread('Mine', $user->id, [$other->id]);
        $theirs = $this->plugin()->threads->createDirectThread('Theirs', $other->id, []);

        $this->login($user);
        $ids = array_map(static fn($t) => $t->id, $this->variable->threads());

        self::assertContains($mine->id, $ids);
        self::assertNotContains($theirs->id, $ids);
    }

    public function testThreadsCanBeFilteredByType(): void
    {
        $user = $this->createUser('var-filter@example.test');
        $other = $this->createUser('var-filter-other@example.test');
        $direct = $this->plugin()->threads->createDirectThread('Direct', $user->id, [$other->id]);
        $this->plugin()->threads->createSupportThread('Support', $user->email, (string)$user, $user->id);

        $this->login($user);
        $ids = array_map(static fn($t) => $t->id, $this->variable->threads(ThreadType::Direct->value));

        self::assertSame([$direct->id], $ids);
    }

    public function testThreadIsScopedToParticipation(): void
    {
        $user = $this->createUser('var-scope@example.test');
        $other = $this->createUser('var-scope-other@example.test');
        $mine = $this->plugin()->threads->createDirectThread('Mine', $user->id, [$other->id]);
        $theirs = $this->plugin()->threads->createDirectThread('Theirs', $other->id, []);

        $this->login($user);

        self::assertSame($mine->id, $this->variable->thread($mine->id)?->id);
        self::assertNull($this->variable->thread($theirs->id), 'Non-participants must not read a thread');
    }

    public function testUnreadCountAndIsUnread(): void
    {
        $reader = $this->createUser('var-reader@example.test');
        $writer = $this->createUser('var-writer@example.test');
        $thread = $this->plugin()->threads->createDirectThread('Chat', $writer->id, [$reader->id]);

        $this->login($reader);
        self::assertSame(0, $this->variable->unreadCount());
        self::assertFalse($this->variable->isUnread($thread->id));

        $this->plugin()->messages->post($thread, ['body' => 'hello', 'authorUserId' => $writer->id]);

        self::assertSame(1, $this->variable->unreadCount());
        self::assertTrue($this->variable->isUnread($thread->id));

        $this->plugin()->participants->markRead($this->plugin()->participants->getForUser($thread->id, $reader->id));

        self::assertSame(0, $this->variable->unreadCount());
        self::assertFalse($this->variable->isUnread($thread->id));
    }

    public function testMessagesExcludesInternalNotes(): void
    {
        $thread = $this->createGuestThread('var-messages@example.test');
        $staff = $this->createStaffUser('var-staff@example.test');
        $this->plugin()->messages->post($thread, [
            'body' => 'internal',
            'authorUserId' => $staff->id,
            'isInternalNote' => true,
        ]);

        self::assertCount(1, $this->variable->messages($thread->id));
    }

    public function testCanStartThreadFlagsFollowSettings(): void
    {
        $settings = $this->plugin()->getSettings();
        $user = $this->createUser('var-flags@example.test');
        $this->login($user);

        $originalGuest = $settings->allowGuestThreads;
        $originalUser = $settings->allowUserThreads;

        try {
            $settings->allowGuestThreads = true;
            $settings->allowUserThreads = true;
            self::assertTrue($this->variable->canStartGuestThread());
            self::assertTrue($this->variable->canStartUserThread());

            $settings->allowGuestThreads = false;
            $settings->allowUserThreads = false;
            self::assertFalse($this->variable->canStartGuestThread());
            self::assertFalse($this->variable->canStartUserThread());
        } finally {
            $settings->allowGuestThreads = $originalGuest;
            $settings->allowUserThreads = $originalUser;
        }
    }
}
