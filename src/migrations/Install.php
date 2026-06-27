<?php

namespace justinholtweb\pigeon\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->_createTables();
        $this->_createIndexes();
        $this->_addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Drop in FK-dependency order (children first).
        $this->dropTableIfExists('{{%pigeon_message_reads}}');
        $this->dropTableIfExists('{{%pigeon_attachments}}');
        $this->dropTableIfExists('{{%pigeon_participants}}');
        $this->dropTableIfExists('{{%pigeon_messages}}');
        $this->dropTableIfExists('{{%pigeon_threads}}');

        return true;
    }

    private function _createTables(): void
    {
        // Threads — backing data for the Thread element. id is also the element id.
        $this->createTable('{{%pigeon_threads}}', [
            'id' => $this->integer()->notNull(),
            'type' => $this->string(20)->notNull()->defaultValue('support'),
            'threadStatus' => $this->string(20)->notNull()->defaultValue('open'),
            'assigneeId' => $this->integer()->null(),
            'starterUserId' => $this->integer()->null(),
            'starterEmail' => $this->string(255)->null(),
            'lastMessageId' => $this->integer()->null(),
            'lastMessageAt' => $this->dateTime()->null(),
            'lastMessageUserId' => $this->integer()->null(),
            'closedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        // Messages — append-only conversation entries.
        $this->createTable('{{%pigeon_messages}}', [
            'id' => $this->primaryKey(),
            'threadId' => $this->integer()->notNull(),
            'authorUserId' => $this->integer()->null(),
            'authorEmail' => $this->string(255)->null(),
            'authorName' => $this->string(255)->null(),
            'body' => $this->text()->notNull(),
            'isInternalNote' => $this->boolean()->notNull()->defaultValue(false),
            'isSystem' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Participants — the 2+ parties on a thread; holds per-party read state + guest token.
        $this->createTable('{{%pigeon_participants}}', [
            'id' => $this->primaryKey(),
            'threadId' => $this->integer()->notNull(),
            'userId' => $this->integer()->null(),
            'email' => $this->string(255)->null(),
            'name' => $this->string(255)->null(),
            'role' => $this->string(20)->notNull()->defaultValue('participant'),
            'tokenHash' => $this->string(64)->null(),
            'tokenExpiresAt' => $this->dateTime()->null(),
            'lastReadMessageId' => $this->integer()->null(),
            'lastReadAt' => $this->dateTime()->null(),
            'notify' => $this->boolean()->notNull()->defaultValue(true),
            'leftAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Attachments — files on a message, stored as real Craft assets.
        $this->createTable('{{%pigeon_attachments}}', [
            'id' => $this->primaryKey(),
            'messageId' => $this->integer()->notNull(),
            'assetId' => $this->integer()->null(),
            'filename' => $this->string(255)->null(),
            'kind' => $this->string(40)->null(),
            'size' => $this->bigInteger()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Message reads — granular "seen by X at T" receipts.
        $this->createTable('{{%pigeon_message_reads}}', [
            'id' => $this->primaryKey(),
            'messageId' => $this->integer()->notNull(),
            'participantId' => $this->integer()->notNull(),
            'readAt' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function _createIndexes(): void
    {
        // Threads
        $this->createIndex(null, '{{%pigeon_threads}}', ['threadStatus']);
        $this->createIndex(null, '{{%pigeon_threads}}', ['type']);
        $this->createIndex(null, '{{%pigeon_threads}}', ['assigneeId']);
        $this->createIndex(null, '{{%pigeon_threads}}', ['starterUserId']);
        $this->createIndex(null, '{{%pigeon_threads}}', ['lastMessageAt']);

        // Messages
        $this->createIndex(null, '{{%pigeon_messages}}', ['threadId', 'dateCreated']);
        $this->createIndex(null, '{{%pigeon_messages}}', ['authorUserId']);
        $this->createIndex(null, '{{%pigeon_messages}}', ['isInternalNote']);

        // Participants — unique per (thread,user) and (thread,email); NULLs allowed repeatedly.
        $this->createIndex(null, '{{%pigeon_participants}}', ['threadId', 'userId'], true);
        $this->createIndex(null, '{{%pigeon_participants}}', ['threadId', 'email'], true);
        $this->createIndex(null, '{{%pigeon_participants}}', ['tokenHash'], true);
        $this->createIndex(null, '{{%pigeon_participants}}', ['userId']);

        // Attachments
        $this->createIndex(null, '{{%pigeon_attachments}}', ['messageId']);
        $this->createIndex(null, '{{%pigeon_attachments}}', ['assetId']);

        // Message reads
        $this->createIndex(null, '{{%pigeon_message_reads}}', ['messageId', 'participantId'], true);
        $this->createIndex(null, '{{%pigeon_message_reads}}', ['participantId']);
    }

    private function _addForeignKeys(): void
    {
        // Threads → elements / users
        $this->addForeignKey(null, '{{%pigeon_threads}}', ['id'], '{{%elements}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%pigeon_threads}}', ['assigneeId'], '{{%users}}', ['id'], 'SET NULL', null);
        $this->addForeignKey(null, '{{%pigeon_threads}}', ['starterUserId'], '{{%users}}', ['id'], 'SET NULL', null);

        // Messages → threads / users
        $this->addForeignKey(null, '{{%pigeon_messages}}', ['threadId'], '{{%pigeon_threads}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%pigeon_messages}}', ['authorUserId'], '{{%users}}', ['id'], 'SET NULL', null);

        // Participants → threads / users
        $this->addForeignKey(null, '{{%pigeon_participants}}', ['threadId'], '{{%pigeon_threads}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%pigeon_participants}}', ['userId'], '{{%users}}', ['id'], 'CASCADE', null);

        // Attachments → messages / assets
        $this->addForeignKey(null, '{{%pigeon_attachments}}', ['messageId'], '{{%pigeon_messages}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%pigeon_attachments}}', ['assetId'], '{{%assets}}', ['id'], 'SET NULL', null);

        // Message reads → messages / participants
        $this->addForeignKey(null, '{{%pigeon_message_reads}}', ['messageId'], '{{%pigeon_messages}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%pigeon_message_reads}}', ['participantId'], '{{%pigeon_participants}}', ['id'], 'CASCADE', null);
    }
}
