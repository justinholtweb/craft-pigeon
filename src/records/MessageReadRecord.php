<?php

namespace justinholtweb\pigeon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $messageId
 * @property int $participantId
 * @property string $readAt
 */
class MessageReadRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pigeon_message_reads}}';
    }
}
