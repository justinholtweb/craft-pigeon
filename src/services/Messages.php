<?php

namespace justinholtweb\pigeon\services;

use Craft;
use craft\helpers\Db;
use DateTime;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ParticipantRole;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\enums\ThreadType;
use justinholtweb\pigeon\events\MessageEvent;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\records\AttachmentRecord;
use justinholtweb\pigeon\records\MessageRecord;
use RuntimeException;
use yii\base\Component;

class Messages extends Component
{
    public const EVENT_AFTER_POST_MESSAGE = 'afterPostMessage';

    /**
     * Post a message (or internal note / system event) to a thread. This is the
     * single entry point that updates the thread and fans out notifications.
     *
     * @param array{
     *     body?: string,
     *     authorUserId?: int|null,
     *     authorEmail?: string|null,
     *     authorName?: string|null,
     *     isInternalNote?: bool,
     *     isSystem?: bool,
     *     attachmentAssetIds?: int[],
     * } $config
     */
    public function post(Thread $thread, array $config): MessageRecord
    {
        $message = new MessageRecord();
        $message->threadId = $thread->id;
        $message->authorUserId = $config['authorUserId'] ?? null;
        $message->authorEmail = isset($config['authorEmail']) ? mb_strtolower(trim($config['authorEmail'])) : null;
        $message->authorName = $config['authorName'] ?? null;
        $message->body = trim($config['body'] ?? '');
        $message->isInternalNote = (bool)($config['isInternalNote'] ?? false);
        $message->isSystem = (bool)($config['isSystem'] ?? false);

        if ($message->body === '' && empty($config['attachmentAssetIds']) && !$message->isSystem) {
            throw new RuntimeException('Cannot post an empty message.');
        }

        if (!$message->save(false)) {
            throw new RuntimeException('Could not save message.');
        }

        // Attachments (already-saved Craft assets).
        foreach ($config['attachmentAssetIds'] ?? [] as $assetId) {
            $asset = Craft::$app->getAssets()->getAssetById((int)$assetId);
            $attachment = new AttachmentRecord();
            $attachment->messageId = $message->id;
            $attachment->assetId = (int)$assetId;
            $attachment->filename = $asset?->getFilename();
            $attachment->kind = $asset?->kind;
            $attachment->size = $asset ? (int)$asset->size : null;
            $attachment->save(false);
        }

        $participants = Plugin::getInstance()->participants;

        // Ensure the author is a tracked participant and mark this message read for them.
        $authorParticipant = null;
        if ($message->authorUserId) {
            $role = $thread->type === ThreadType::Support->value && $this->isStaff($message->authorUserId)
                ? ParticipantRole::Admin->value
                : ParticipantRole::Participant->value;
            $authorParticipant = $participants->ensureUser($thread->id, $message->authorUserId, $role);
        } elseif ($message->authorEmail) {
            $authorParticipant = $participants->ensureGuest($thread->id, $message->authorEmail, $message->authorName);
        }
        if ($authorParticipant) {
            $participants->markRead($authorParticipant, $message->id);
        }

        // Update thread denormalized fields + status for real (visible) messages.
        if (!$message->isInternalNote && !$message->isSystem) {
            $thread->lastMessageId = $message->id;
            $thread->lastMessageAt = Db::prepareDateForDb(new DateTime());
            $thread->lastMessageUserId = $message->authorUserId;

            if ($thread->type === ThreadType::Support->value && $thread->threadStatus !== ThreadStatus::Closed->value) {
                // Customer/guest reply → needs staff; staff reply → awaiting customer.
                $fromStaff = $message->authorUserId && $this->isStaff($message->authorUserId);
                $thread->threadStatus = $fromStaff ? ThreadStatus::Open->value : ThreadStatus::Pending->value;
            }

            Craft::$app->getElements()->saveElement($thread);
        }

        // Fan out notifications (email + CP/on-site read models update implicitly).
        Plugin::getInstance()->notifications->notifyNewMessage($thread, $message);

        if ($this->hasEventHandlers(self::EVENT_AFTER_POST_MESSAGE)) {
            $event = new MessageEvent();
            $event->message = $message;
            $event->thread = $thread;
            $this->trigger(self::EVENT_AFTER_POST_MESSAGE, $event);
        }

        return $message;
    }

    /**
     * @return MessageRecord[] Messages for a thread, oldest first. Internal notes
     *                         are excluded unless $includeInternal is true.
     */
    public function getForThread(int $threadId, bool $includeInternal = false): array
    {
        $query = MessageRecord::find()
            ->where(['threadId' => $threadId])
            ->orderBy(['id' => SORT_ASC]);

        if (!$includeInternal) {
            $query->andWhere(['isInternalNote' => false]);
        }

        return $query->all();
    }

    private function isStaff(int $userId): bool
    {
        $user = Craft::$app->getUsers()->getUserById($userId);
        return $user !== null && ($user->admin || $user->can('pigeon:manageThreads'));
    }
}
