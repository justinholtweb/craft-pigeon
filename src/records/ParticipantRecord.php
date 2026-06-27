<?php

namespace justinholtweb\pigeon\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $threadId
 * @property int|null $userId
 * @property string|null $email
 * @property string|null $name
 * @property string $role
 * @property string|null $tokenHash
 * @property string|null $tokenExpiresAt
 * @property int|null $lastReadMessageId
 * @property string|null $lastReadAt
 * @property bool $notify
 * @property string|null $leftAt
 */
class ParticipantRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pigeon_participants}}';
    }

    public function getThread(): ActiveQueryInterface
    {
        return $this->hasOne(ThreadRecord::class, ['id' => 'threadId']);
    }
}
