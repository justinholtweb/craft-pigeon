<?php

namespace justinholtweb\pigeon\records;

use craft\db\ActiveRecord;
use craft\records\Asset;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $messageId
 * @property int|null $assetId
 * @property string|null $filename
 * @property string|null $kind
 * @property int|null $size
 */
class AttachmentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pigeon_attachments}}';
    }

    public function getAsset(): ActiveQueryInterface
    {
        return $this->hasOne(Asset::class, ['id' => 'assetId']);
    }
}
