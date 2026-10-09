<?php

namespace justinholtweb\pigeontests\unit\migrations;

use Craft;
use craft\test\TestCase;

/**
 * Asserts the shape the Install migration left behind. (The migration itself is
 * run once by the test bootstrap when the plugin is installed.)
 */
class InstallTest extends TestCase
{
    /**
     * @dataProvider tableProvider
     */
    public function testTableHasExpectedColumns(string $table, array $columns): void
    {
        $schema = Craft::$app->getDb()->getTableSchema("{{%$table}}");

        self::assertNotNull($schema, "Table $table should exist");
        foreach ($columns as $column) {
            self::assertArrayHasKey($column, $schema->columns, "$table.$column should exist");
        }
    }

    public static function tableProvider(): array
    {
        return [
            ['pigeon_threads', [
                'id', 'type', 'threadStatus', 'assigneeId', 'starterUserId', 'starterEmail',
                'lastMessageId', 'lastMessageAt', 'lastMessageUserId', 'closedAt', 'uid',
            ]],
            ['pigeon_messages', [
                'id', 'threadId', 'authorUserId', 'authorEmail', 'authorName', 'body',
                'isInternalNote', 'isSystem', 'uid',
            ]],
            ['pigeon_participants', [
                'id', 'threadId', 'userId', 'email', 'name', 'role', 'tokenHash',
                'tokenExpiresAt', 'replyToken', 'lastReadMessageId', 'lastReadAt', 'notify', 'leftAt', 'uid',
            ]],
            ['pigeon_attachments', ['id', 'messageId', 'assetId', 'filename', 'kind', 'size', 'uid']],
            ['pigeon_message_reads', ['id', 'messageId', 'participantId', 'readAt', 'uid']],
            ['pigeon_inbound', [
                'id', 'provider', 'messageHash', 'messageId', 'fromEmail', 'subject', 'status', 'reason',
                'payload', 'threadId', 'postedMessageId', 'attempts', 'dateProcessed', 'uid',
            ]],
            ['pigeon_email_threads', ['id', 'threadId', 'participantId', 'messageHash', 'messageId', 'direction', 'uid']],
        ];
    }

    public function testThreadsPrimaryKeyIsTheElementId(): void
    {
        $schema = Craft::$app->getDb()->getTableSchema('{{%pigeon_threads}}');

        self::assertSame(['id'], $schema->primaryKey);
        self::assertFalse($schema->columns['id']->autoIncrement, 'Thread IDs come from the elements table');
    }

    /**
     * @dataProvider uniqueIndexProvider
     */
    public function testUniqueIndexesExist(string $table, array $columns): void
    {
        $indexes = Craft::$app->getDb()->getSchema()->findUniqueIndexes(
            Craft::$app->getDb()->getTableSchema("{{%$table}}"),
        );

        $found = false;
        foreach ($indexes as $indexColumns) {
            if ($indexColumns === $columns) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, "$table should have a unique index on " . implode(', ', $columns));
    }

    public static function uniqueIndexProvider(): array
    {
        return [
            'participants per user' => ['pigeon_participants', ['threadId', 'userId']],
            'participants per email' => ['pigeon_participants', ['threadId', 'email']],
            'participant token' => ['pigeon_participants', ['tokenHash']],
            'participant reply token' => ['pigeon_participants', ['replyToken']],
            'inbound dedupe' => ['pigeon_inbound', ['messageHash']],
            'email thread Message-ID' => ['pigeon_email_threads', ['messageHash']],
            'one receipt per message and participant' => ['pigeon_message_reads', ['messageId', 'participantId']],
        ];
    }

    public function testForeignKeysCascadeFromThreads(): void
    {
        $rows = (new \craft\db\Query())
            ->select(['TABLE_NAME', 'REFERENCED_TABLE_NAME', 'COLUMN_NAME'])
            ->from('information_schema.KEY_COLUMN_USAGE')
            ->where([
                'TABLE_SCHEMA' => Craft::$app->getDb()->createCommand('SELECT DATABASE()')->queryScalar(),
                'REFERENCED_TABLE_NAME' => 'pigeon_threads',
            ])
            ->all();

        $children = array_column($rows, 'TABLE_NAME');

        self::assertContains('pigeon_messages', $children);
        self::assertContains('pigeon_participants', $children);
    }
}
