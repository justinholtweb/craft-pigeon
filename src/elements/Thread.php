<?php

namespace justinholtweb\pigeon\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\User;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use justinholtweb\pigeon\elements\db\ThreadQuery;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\enums\ThreadType;
use justinholtweb\pigeon\records\ThreadRecord;
use yii\base\InvalidConfigException;

class Thread extends Element
{
    public string $type = 'support';
    public string $threadStatus = 'open';
    public ?int $assigneeId = null;
    public ?int $starterUserId = null;
    public ?string $starterEmail = null;
    public ?int $lastMessageId = null;
    public ?string $lastMessageAt = null;
    public ?int $lastMessageUserId = null;
    public ?string $closedAt = null;

    private ?User $_assignee = null;

    public static function displayName(): string
    {
        return Craft::t('pigeon', 'Thread');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('pigeon', 'Threads');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('pigeon', 'thread');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('pigeon', 'threads');
    }

    public static function refHandle(): ?string
    {
        return 'thread';
    }

    public static function hasContent(): bool
    {
        return true;
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            ThreadStatus::Open->value => ['label' => Craft::t('pigeon', 'Open'), 'color' => 'green'],
            ThreadStatus::Pending->value => ['label' => Craft::t('pigeon', 'Pending'), 'color' => 'orange'],
            ThreadStatus::Closed->value => ['label' => Craft::t('pigeon', 'Closed'), 'color' => 'grey'],
        ];
    }

    public static function find(): ThreadQuery
    {
        return new ThreadQuery(static::class);
    }

    public static function defineSources(?string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('pigeon', 'All threads'),
            ],
            ['heading' => Craft::t('pigeon', 'Status')],
            [
                'key' => 'status:pending',
                'label' => Craft::t('pigeon', 'Pending (needs reply)'),
                'criteria' => ['threadStatus' => ThreadStatus::Pending->value],
            ],
            [
                'key' => 'status:open',
                'label' => Craft::t('pigeon', 'Open'),
                'criteria' => ['threadStatus' => ThreadStatus::Open->value],
            ],
            [
                'key' => 'status:closed',
                'label' => Craft::t('pigeon', 'Closed'),
                'criteria' => ['threadStatus' => ThreadStatus::Closed->value],
            ],
            ['heading' => Craft::t('pigeon', 'Type')],
            [
                'key' => 'type:support',
                'label' => Craft::t('pigeon', 'Support'),
                'criteria' => ['type' => ThreadType::Support->value],
            ],
            [
                'key' => 'type:direct',
                'label' => Craft::t('pigeon', 'Direct'),
                'criteria' => ['type' => ThreadType::Direct->value],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => Craft::t('pigeon', 'Subject'),
            'threadStatus' => Craft::t('pigeon', 'Status'),
            'type' => Craft::t('pigeon', 'Type'),
            'starter' => Craft::t('pigeon', 'Started by'),
            'assignee' => Craft::t('pigeon', 'Assigned to'),
            'lastMessageAt' => Craft::t('pigeon', 'Last activity'),
            'dateCreated' => Craft::t('pigeon', 'Date created'),
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['threadStatus', 'type', 'starter', 'assignee', 'lastMessageAt'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'starterEmail'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('pigeon', 'Subject'),
            [
                'label' => Craft::t('pigeon', 'Last activity'),
                'orderBy' => 'pigeon_threads.lastMessageAt',
                'attribute' => 'lastMessageAt',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('pigeon', 'Date created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
                'defaultDir' => 'desc',
            ],
        ];
    }

    protected static function defineActions(?string $source = null): array
    {
        return [
            Delete::class,
            Restore::class,
        ];
    }

    public function getStatus(): ?string
    {
        return $this->threadStatus;
    }

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("pigeon/threads/{$this->id}");
    }

    public function getAssignee(): ?User
    {
        if ($this->_assignee !== null) {
            return $this->_assignee;
        }
        if (!$this->assigneeId) {
            return null;
        }
        return $this->_assignee = Craft::$app->getUsers()->getUserById($this->assigneeId);
    }

    public function getStarter(): ?User
    {
        if (!$this->starterUserId) {
            return null;
        }
        return Craft::$app->getUsers()->getUserById($this->starterUserId);
    }

    protected function tableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'threadStatus':
                $enum = ThreadStatus::tryFrom($this->threadStatus) ?? ThreadStatus::Open;
                return '<span class="status ' . $enum->color() . '"></span>' . Html::encode($enum->label());
            case 'type':
                $enum = ThreadType::tryFrom($this->type) ?? ThreadType::Support;
                return Html::encode($enum->label());
            case 'starter':
                $user = $this->getStarter();
                if ($user) {
                    return Html::encode((string)$user);
                }
                return $this->starterEmail ? Html::encode($this->starterEmail) : '—';
            case 'assignee':
                $user = $this->getAssignee();
                return $user ? Html::encode((string)$user) : '—';
            default:
                return parent::tableAttributeHtml($attribute);
        }
    }

    public function canView(User $user): bool
    {
        return $user->can('pigeon:manageThreads') || $user->can('pigeon:accessPlugin');
    }

    public function canSave(User $user): bool
    {
        return $user->can('pigeon:manageThreads');
    }

    public function canDelete(User $user): bool
    {
        return $user->can('pigeon:manageThreads');
    }

    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['type'], 'in', 'range' => ThreadType::values()];
        $rules[] = [['threadStatus'], 'in', 'range' => ThreadStatus::values()];
        $rules[] = [['starterEmail'], 'email', 'skipOnEmpty' => true];
        $rules[] = [['assigneeId', 'starterUserId', 'lastMessageId', 'lastMessageUserId'], 'integer'];

        return $rules;
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            if ($isNew) {
                $record = new ThreadRecord();
                $record->id = $this->id;
            } else {
                $record = ThreadRecord::findOne($this->id);
                if (!$record) {
                    throw new InvalidConfigException("Invalid thread ID: {$this->id}");
                }
            }

            $record->type = $this->type;
            $record->threadStatus = $this->threadStatus;
            $record->assigneeId = $this->assigneeId;
            $record->starterUserId = $this->starterUserId;
            $record->starterEmail = $this->starterEmail;
            $record->lastMessageId = $this->lastMessageId;
            $record->lastMessageAt = $this->lastMessageAt;
            $record->lastMessageUserId = $this->lastMessageUserId;
            $record->closedAt = $this->closedAt;
            $record->save(false);
        }

        parent::afterSave($isNew);
    }
}
