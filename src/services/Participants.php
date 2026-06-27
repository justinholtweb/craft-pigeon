<?php

namespace justinholtweb\pigeon\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ParticipantRole;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\records\MessageReadRecord;
use justinholtweb\pigeon\records\MessageRecord;
use justinholtweb\pigeon\records\ParticipantRecord;
use yii\base\Component;

class Participants extends Component
{
    /**
     * Find or create a participant row for a Craft user on a thread.
     */
    public function ensureUser(int $threadId, int $userId, string $role = ParticipantRole::Participant->value, bool $notify = true): ParticipantRecord
    {
        $record = ParticipantRecord::findOne(['threadId' => $threadId, 'userId' => $userId]);
        if ($record) {
            return $record;
        }

        $user = Craft::$app->getUsers()->getUserById($userId);

        $record = new ParticipantRecord();
        $record->threadId = $threadId;
        $record->userId = $userId;
        $record->email = $user?->email;
        $record->name = $user ? (string)$user : null;
        $record->role = $role;
        $record->notify = $notify;
        $record->save(false);

        return $record;
    }

    /**
     * Find or create a guest participant row (no Craft account) by email.
     */
    public function ensureGuest(int $threadId, string $email, ?string $name = null, string $role = ParticipantRole::Guest->value): ParticipantRecord
    {
        $email = trim(mb_strtolower($email));
        $record = ParticipantRecord::findOne(['threadId' => $threadId, 'email' => $email, 'userId' => null]);
        if ($record) {
            return $record;
        }

        $record = new ParticipantRecord();
        $record->threadId = $threadId;
        $record->userId = null;
        $record->email = $email;
        $record->name = $name;
        $record->role = $role;
        $record->notify = true;
        $record->save(false);

        return $record;
    }

    /**
     * @return ParticipantRecord[]
     */
    public function getForThread(int $threadId): array
    {
        return ParticipantRecord::find()
            ->where(['threadId' => $threadId])
            ->all();
    }

    public function getForUser(int $threadId, int $userId): ?ParticipantRecord
    {
        return ParticipantRecord::findOne(['threadId' => $threadId, 'userId' => $userId]);
    }

    /**
     * Generate a fresh signed access token for a guest participant and return
     * the raw token (only ever sent by email — we store only its hash).
     */
    public function mintToken(ParticipantRecord $participant): string
    {
        $raw = StringHelper::UUID() . StringHelper::randomString(24);
        $participant->tokenHash = $this->hashToken($raw);

        $lifetime = max(1, Plugin::getInstance()->getSettings()->guestTokenLifetimeDays);
        $expires = (new DateTime())->add(new DateInterval("P{$lifetime}D"));
        $participant->tokenExpiresAt = Db::prepareDateForDb($expires);

        $participant->save(false);

        return $raw;
    }

    /**
     * Resolve a guest participant from a raw token, enforcing expiry.
     */
    public function findByToken(string $rawToken): ?ParticipantRecord
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return null;
        }

        $record = ParticipantRecord::findOne(['tokenHash' => $this->hashToken($rawToken)]);
        if (!$record || $record->leftAt !== null) {
            return null;
        }

        if ($record->tokenExpiresAt !== null) {
            $expires = new DateTime($record->tokenExpiresAt);
            if ($expires < new DateTime()) {
                return null;
            }
        }

        return $record;
    }

    /**
     * @return ParticipantRecord[] Guest participants matching an email on active threads.
     */
    public function getActiveGuestsByEmail(string $email): array
    {
        $email = trim(mb_strtolower($email));
        if ($email === '') {
            return [];
        }

        return ParticipantRecord::find()
            ->where(['email' => $email, 'userId' => null, 'leftAt' => null])
            ->all();
    }

    /**
     * Advance a participant's read high-water mark and record per-message receipts.
     */
    public function markRead(ParticipantRecord $participant, ?int $upToMessageId = null): void
    {
        if ($upToMessageId === null) {
            $upToMessageId = (int)MessageRecord::find()
                ->where(['threadId' => $participant->threadId])
                ->max('id');
        }

        if (!$upToMessageId) {
            return;
        }

        $previous = (int)($participant->lastReadMessageId ?? 0);

        $now = Db::prepareDateForDb(new DateTime());
        $participant->lastReadAt = $now;
        if ($upToMessageId > $previous) {
            $participant->lastReadMessageId = $upToMessageId;
        }
        $participant->save(false);

        // Write granular receipts for newly-read messages (bounded by the gap).
        if ($upToMessageId > $previous) {
            $newIds = MessageRecord::find()
                ->select(['id'])
                ->where(['threadId' => $participant->threadId])
                ->andWhere(['>', 'id', $previous])
                ->andWhere(['<=', 'id', $upToMessageId])
                ->column();

            foreach ($newIds as $messageId) {
                $exists = MessageReadRecord::findOne([
                    'messageId' => $messageId,
                    'participantId' => $participant->id,
                ]);
                if (!$exists) {
                    $read = new MessageReadRecord();
                    $read->messageId = (int)$messageId;
                    $read->participantId = $participant->id;
                    $read->readAt = $now;
                    $read->save(false);
                }
            }
        }
    }

    /**
     * Number of threads with unread messages for a logged-in user (excludes closed).
     */
    public function unreadThreadCountForUser(int $userId): int
    {
        return (int)Thread::find()
            ->forUser($userId)
            ->unread()
            ->threadStatus(['open', 'pending'])
            ->count();
    }

    private function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
