<?php

namespace justinholtweb\pigeon\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $threadId
 * @property int|null $authorUserId
 * @property string|null $authorEmail
 * @property string|null $authorName
 * @property string $body
 * @property bool $isInternalNote
 * @property bool $isSystem
 */
class MessageRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pigeon_messages}}';
    }

    public function getThread(): ActiveQueryInterface
    {
        return $this->hasOne(ThreadRecord::class, ['id' => 'threadId']);
    }

    public function getAttachments(): ActiveQueryInterface
    {
        return $this->hasMany(AttachmentRecord::class, ['messageId' => 'id']);
    }
}
