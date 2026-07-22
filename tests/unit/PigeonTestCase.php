<?php

namespace justinholtweb\pigeontests\unit;

use Craft;
use craft\elements\User;
use craft\test\TestCase;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\records\MessageRecord;

/**
 * Shared fixtures and helpers for Pigeon's integration tests.
 */
abstract class PigeonTestCase extends TestCase
{
    protected function plugin(): Plugin
    {
        /** @var Plugin $plugin */
        $plugin = Plugin::getInstance();
        return $plugin;
    }

    /**
     * Create and save a Craft user.
     */
    protected function createUser(string $email, bool $admin = false, array $permissions = []): User
    {
        $user = new User();
        $user->username = explode('@', $email)[0] . '_' . substr(md5($email . microtime()), 0, 6);
        $user->email = $email;
        $user->firstName = 'Test';
        $user->lastName = 'User';
        $user->admin = $admin;

        if (!Craft::$app->getElements()->saveElement($user)) {
            $this->fail('Could not save user: ' . implode(', ', $user->getErrorSummary(true)));
        }

        if ($permissions) {
            Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
        }

        return $user;
    }

    /**
     * A staff member: not an admin, but granted Pigeon thread management.
     */
    protected function createStaffUser(string $email = 'staff@example.test'): User
    {
        return $this->createUser($email, false, ['pigeon:accessplugin', 'pigeon:managethreads']);
    }

    /**
     * A guest-started support thread with one inbound message.
     */
    protected function createGuestThread(
        string $email = 'guest@example.test',
        string $subject = 'Help me',
        string $body = 'My widget is broken.',
    ): Thread {
        $thread = $this->plugin()->threads->createSupportThread($subject, $email, 'Guest Person');
        $this->plugin()->messages->post($thread, [
            'body' => $body,
            'authorEmail' => $email,
            'authorName' => 'Guest Person',
        ]);

        return $this->reloadThread($thread->id);
    }

    protected function reloadThread(int $id): Thread
    {
        $thread = Thread::find()->id($id)->status(null)->one();
        $this->assertNotNull($thread, "Thread $id could not be reloaded.");

        return $thread;
    }

    /**
     * @return MessageRecord[]
     */
    protected function messagesFor(int $threadId, bool $includeInternal = true): array
    {
        return $this->plugin()->messages->getForThread($threadId, $includeInternal);
    }

    /**
     * Number of jobs currently waiting in Craft's queue.
     */
    protected function queuedJobCount(): int
    {
        return (int)(new \craft\db\Query())
            ->from(\craft\db\Table::QUEUE)
            ->count();
    }

    /**
     * Delete every queued job so a test can count only what it triggers.
     */
    protected function clearQueue(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(\craft\db\Table::QUEUE)
            ->execute();
    }
}
