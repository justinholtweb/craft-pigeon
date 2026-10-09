<?php

namespace justinholtweb\pigeon\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\StringHelper;
use justinholtweb\pigeon\mail\Addresses;
use justinholtweb\pigeon\services\Inbound;

/**
 * Reply by email.
 *
 * - `pigeon_participants.replyToken`, the tag in each participant's reply address, backfilled for
 *   every participant that already exists so a reply to an email sent before the upgrade still
 *   finds its conversation once the feature is switched on.
 * - `pigeon_inbound`, one row per received email: the dedupe key, the rate-limit count and the
 *   record of what became of it.
 * - `pigeon_email_threads`, the Message-IDs Pigeon has sent and received per conversation, for the
 *   replies whose tagged address was lost on the way.
 */
class m261009_000000_reply_by_email extends Migration
{
    public function safeUp(): bool
    {
        $participants = '{{%pigeon_participants}}';

        if (!$this->db->columnExists($participants, 'replyToken')) {
            $this->addColumn($participants, 'replyToken', (string)$this->char(32)->null()->after('tokenExpiresAt'));

            foreach ((new Query())->select(['id'])->from([$participants])->column($this->db) as $id) {
                $this->update($participants, [
                    'replyToken' => StringHelper::randomStringWithChars(Addresses::TOKEN_ALPHABET, Addresses::TOKEN_LENGTH),
                ], ['id' => (int)$id], [], false);
            }

            $this->createIndex(null, $participants, ['replyToken'], true);
        }

        if (!$this->db->tableExists(Inbound::TABLE_INBOUND)) {
            Inbound::createTables($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261009_000000_reply_by_email cannot be reverted.\n";

        return false;
    }
}
