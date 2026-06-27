<?php

namespace justinholtweb\pigeon\variables;

use Craft;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\records\MessageRecord;
use justinholtweb\pigeon\records\ParticipantRecord;

/**
 * `craft.pigeon` Twig API for the front end.
 */
class PigeonVariable
{
    /**
     * Threads the current user participates in (most recent first).
     *
     * @return Thread[]
     */
    public function threads(?string $type = null): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return [];
        }

        return Plugin::getInstance()->threads->getThreadsForUser($user, $type);
    }

    /**
     * A single thread, only if the current user participates in it.
     */
    public function thread(int $id): ?Thread
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return null;
        }

        return Thread::find()
            ->id($id)
            ->forUser($user->id)
            ->status(null)
            ->one();
    }

    /**
     * Number of threads with unread messages for the current user.
     */
    public function unreadCount(): int
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return 0;
        }

        return Plugin::getInstance()->participants->unreadThreadCountForUser($user->id);
    }

    /**
     * Whether a thread is unread for the current user.
     */
    public function isUnread(int $threadId): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return false;
        }

        return Thread::find()
            ->id($threadId)
            ->forUser($user->id)
            ->unread()
            ->status(null)
            ->exists();
    }

    /**
     * Visible messages for a thread (internal notes excluded).
     *
     * @return MessageRecord[]
     */
    public function messages(int $threadId): array
    {
        return Plugin::getInstance()->messages->getForThread($threadId, includeInternal: false);
    }

    public function canStartGuestThread(): bool
    {
        return Plugin::getInstance()->getSettings()->allowGuestThreads;
    }

    public function canStartUserThread(): bool
    {
        return Plugin::getInstance()->getSettings()->allowUserThreads
            && Craft::$app->getUser()->getIdentity() !== null;
    }
}
