<?php

namespace justinholtweb\pigeon\services;

use Craft;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ParticipantRole;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\enums\ThreadType;
use justinholtweb\pigeon\Plugin;
use RuntimeException;
use yii\base\Component;

class Threads extends Component
{
    public function getById(int $id): ?Thread
    {
        return Thread::find()->id($id)->status(null)->one();
    }

    /**
     * Create a support thread started by a guest (email only) or a logged-in user.
     */
    public function createSupportThread(
        string $subject,
        ?string $starterEmail = null,
        ?string $starterName = null,
        ?int $starterUserId = null,
    ): Thread {
        $thread = new Thread();
        $thread->title = $subject !== '' ? $subject : Craft::t('pigeon', 'Support request');
        $thread->type = ThreadType::Support->value;
        $thread->threadStatus = ThreadStatus::Pending->value;
        $thread->starterUserId = $starterUserId;
        $thread->starterEmail = $starterEmail ? mb_strtolower(trim($starterEmail)) : null;

        if (!Craft::$app->getElements()->saveElement($thread)) {
            throw new RuntimeException('Could not save thread: ' . implode(', ', $thread->getErrorSummary(true)));
        }

        // Register the starter as the owning participant.
        $participants = Plugin::getInstance()->participants;
        if ($starterUserId) {
            $participants->ensureUser($thread->id, $starterUserId, ParticipantRole::Owner->value);
        } elseif ($thread->starterEmail) {
            $participants->ensureGuest($thread->id, $thread->starterEmail, $starterName, ParticipantRole::Owner->value);
        }

        return $thread;
    }

    /**
     * Create a direct (user-to-user) thread.
     *
     * @param int[] $recipientUserIds
     */
    public function createDirectThread(string $subject, int $starterUserId, array $recipientUserIds): Thread
    {
        $thread = new Thread();
        $thread->title = $subject !== '' ? $subject : Craft::t('pigeon', 'New message');
        $thread->type = ThreadType::Direct->value;
        $thread->threadStatus = ThreadStatus::Open->value;
        $thread->starterUserId = $starterUserId;

        if (!Craft::$app->getElements()->saveElement($thread)) {
            throw new RuntimeException('Could not save thread: ' . implode(', ', $thread->getErrorSummary(true)));
        }

        $participants = Plugin::getInstance()->participants;
        $participants->ensureUser($thread->id, $starterUserId, ParticipantRole::Owner->value);

        foreach (array_unique($recipientUserIds) as $userId) {
            if ((int)$userId !== $starterUserId) {
                $participants->ensureUser($thread->id, (int)$userId, ParticipantRole::Participant->value);
            }
        }

        return $thread;
    }

    /**
     * Assign (or unassign with null) a support thread to a staff user.
     */
    public function assign(Thread $thread, ?int $assigneeId): bool
    {
        $thread->assigneeId = $assigneeId;

        if ($assigneeId) {
            Plugin::getInstance()->participants->ensureUser($thread->id, $assigneeId, ParticipantRole::Admin->value);
        }

        return Craft::$app->getElements()->saveElement($thread);
    }

    /**
     * Change a thread's status, stamping closedAt on close.
     */
    public function setStatus(Thread $thread, ThreadStatus $status): bool
    {
        $thread->threadStatus = $status->value;
        $thread->closedAt = $status === ThreadStatus::Closed ? Db::prepareDateForDb(new DateTime()) : null;

        return Craft::$app->getElements()->saveElement($thread);
    }

    /**
     * Threads a given user participates in, most-recent activity first.
     *
     * @return Thread[]
     */
    public function getThreadsForUser(User $user, ?string $type = null): array
    {
        $query = Thread::find()
            ->forUser($user->id)
            ->status(null)
            ->orderBy(['pigeon_threads.lastMessageAt' => SORT_DESC, 'elements.dateCreated' => SORT_DESC]);

        if ($type !== null) {
            $query->type($type);
        }

        return $query->all();
    }
}
