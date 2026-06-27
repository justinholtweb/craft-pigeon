<?php

namespace justinholtweb\pigeon\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class ThreadQuery extends ElementQuery
{
    public mixed $type = null;
    public mixed $threadStatus = null;
    public ?int $assigneeId = null;
    public ?int $starterUserId = null;

    /** Restrict to threads the given user participates in (and hasn't left). */
    public ?int $forUserId = null;

    /** When combined with forUserId, restrict to threads unread by that user. */
    public bool $unread = false;

    public function type(mixed $value): self
    {
        $this->type = $value;
        return $this;
    }

    public function threadStatus(mixed $value): self
    {
        $this->threadStatus = $value;
        return $this;
    }

    public function assigneeId(?int $value): self
    {
        $this->assigneeId = $value;
        return $this;
    }

    public function starterUserId(?int $value): self
    {
        $this->starterUserId = $value;
        return $this;
    }

    public function forUser(int|\craft\elements\User|null $value): self
    {
        if ($value instanceof \craft\elements\User) {
            $value = $value->id;
        }
        $this->forUserId = $value;
        return $this;
    }

    public function unread(bool $value = true): self
    {
        $this->unread = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('pigeon_threads');

        $this->query->select([
            'pigeon_threads.type',
            'pigeon_threads.threadStatus',
            'pigeon_threads.assigneeId',
            'pigeon_threads.starterUserId',
            'pigeon_threads.starterEmail',
            'pigeon_threads.lastMessageId',
            'pigeon_threads.lastMessageAt',
            'pigeon_threads.lastMessageUserId',
            'pigeon_threads.closedAt',
        ]);

        if ($this->type !== null) {
            $this->subQuery->andWhere(Db::parseParam('pigeon_threads.type', $this->type));
        }

        if ($this->threadStatus !== null) {
            $this->subQuery->andWhere(Db::parseParam('pigeon_threads.threadStatus', $this->threadStatus));
        }

        if ($this->assigneeId !== null) {
            $this->subQuery->andWhere(Db::parseParam('pigeon_threads.assigneeId', $this->assigneeId));
        }

        if ($this->starterUserId !== null) {
            $this->subQuery->andWhere(Db::parseParam('pigeon_threads.starterUserId', $this->starterUserId));
        }

        if ($this->forUserId !== null) {
            $this->subQuery->innerJoin(
                ['pigeon_pp' => '{{%pigeon_participants}}'],
                '[[pigeon_pp.threadId]] = [[pigeon_threads.id]] AND [[pigeon_pp.userId]] = :pigeonForUser AND [[pigeon_pp.leftAt]] IS NULL',
                [':pigeonForUser' => $this->forUserId]
            );

            if ($this->unread) {
                $this->subQuery->andWhere([
                    'and',
                    ['not', ['pigeon_threads.lastMessageId' => null]],
                    '[[pigeon_threads.lastMessageId]] > COALESCE([[pigeon_pp.lastReadMessageId]], 0)',
                    [
                        'or',
                        ['pigeon_threads.lastMessageUserId' => null],
                        ['not', ['pigeon_threads.lastMessageUserId' => $this->forUserId]],
                    ],
                ]);
            }
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            'open' => ['pigeon_threads.threadStatus' => 'open'],
            'pending' => ['pigeon_threads.threadStatus' => 'pending'],
            'closed' => ['pigeon_threads.threadStatus' => 'closed'],
            default => parent::statusCondition($status),
        };
    }
}
