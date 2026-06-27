<?php

namespace justinholtweb\pigeon\services;

use Craft;
use craft\elements\User;
use craft\helpers\App;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ParticipantRole;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\enums\ThreadType;
use justinholtweb\pigeon\jobs\SendMessageNotification;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\records\MessageRecord;
use justinholtweb\pigeon\records\ParticipantRecord;
use yii\base\Component;

class Notifications extends Component
{
    /**
     * Fan out email notifications for a newly-posted message.
     */
    public function notifyNewMessage(Thread $thread, MessageRecord $message): void
    {
        if ($message->isSystem) {
            return;
        }

        $participants = Plugin::getInstance()->participants->getForThread($thread->id);
        $queue = Craft::$app->getQueue();

        foreach ($participants as $participant) {
            if ($this->isAuthor($participant, $message)) {
                continue;
            }
            if (!$participant->notify || $participant->leftAt !== null) {
                continue;
            }
            // Internal notes only go to staff participants.
            if ($message->isInternalNote && $participant->role !== ParticipantRole::Admin->value) {
                continue;
            }
            if (!$participant->email && !$participant->userId) {
                continue;
            }

            $queue->push(new SendMessageNotification([
                'messageId' => $message->id,
                'participantId' => $participant->id,
            ]));
        }

        // Support inbox alerting: a customer/guest message on an unassigned thread
        // has no staff participant yet, so email the configured support addresses.
        if (
            $thread->type === ThreadType::Support->value
            && !$message->isInternalNote
            && !$thread->assigneeId
            && !$this->isFromStaff($message)
        ) {
            $recipients = Plugin::getInstance()->getSettings()->getSupportRecipients();
            if (!$recipients) {
                $systemEmail = App::parseEnv(App::mailSettings()->fromEmail);
                if ($systemEmail) {
                    $recipients = [$systemEmail];
                }
            }

            foreach ($recipients as $email) {
                $queue->push(new SendMessageNotification([
                    'messageId' => $message->id,
                    'adhocEmail' => $email,
                    'staffAlert' => true,
                ]));
            }
        }
    }

    /**
     * Count of support threads needing a staff reply, scoped to threads assigned
     * to the given user or unassigned. Powers the CP nav badge and dashboard widget.
     */
    public function inboxCountForStaff(?User $user = null): int
    {
        $user ??= Craft::$app->getUser()->getIdentity();

        $query = Thread::find()
            ->type(ThreadType::Support->value)
            ->threadStatus(ThreadStatus::Pending->value)
            ->status(null);

        if ($user && !$user->admin) {
            $query->andWhere([
                'or',
                ['pigeon_threads.assigneeId' => null],
                ['pigeon_threads.assigneeId' => $user->id],
            ]);
        }

        return (int)$query->count();
    }

    /**
     * @return Thread[] Recent pending support threads for the dashboard widget.
     */
    public function recentInboxThreads(int $limit = 10): array
    {
        return Thread::find()
            ->type(ThreadType::Support->value)
            ->threadStatus(ThreadStatus::Pending->value)
            ->status(null)
            ->orderBy(['pigeon_threads.lastMessageAt' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    private function isAuthor(ParticipantRecord $participant, MessageRecord $message): bool
    {
        if ($message->authorUserId && $participant->userId) {
            return (int)$participant->userId === (int)$message->authorUserId;
        }
        if ($message->authorEmail && $participant->email && !$participant->userId) {
            return mb_strtolower($participant->email) === mb_strtolower($message->authorEmail);
        }
        return false;
    }

    private function isFromStaff(MessageRecord $message): bool
    {
        if (!$message->authorUserId) {
            return false;
        }
        $user = Craft::$app->getUsers()->getUserById($message->authorUserId);
        return $user !== null && ($user->admin || $user->can('pigeon:manageThreads'));
    }
}
