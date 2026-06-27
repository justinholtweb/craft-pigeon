<?php

namespace justinholtweb\pigeon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $type
 * @property string $threadStatus
 * @property int|null $assigneeId
 * @property int|null $starterUserId
 * @property string|null $starterEmail
 * @property int|null $lastMessageId
 * @property string|null $lastMessageAt
 * @property int|null $lastMessageUserId
 * @property string|null $closedAt
 */
class ThreadRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pigeon_threads}}';
    }
}
