<?php

namespace justinholtweb\pigeon\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\pigeon\Plugin;

class InboxWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('pigeon', 'Pigeon Inbox');
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/pigeon/icon-mask.svg');
    }

    public static function isSelectable(): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        return $user !== null && ($user->admin || $user->can('pigeon:manageThreads') || $user->can('pigeon:accessPlugin'));
    }

    public function getTitle(): ?string
    {
        return Craft::t('pigeon', 'Pigeon Inbox');
    }

    public function getBodyHtml(): ?string
    {
        $notifications = Plugin::getInstance()->notifications;

        return Craft::$app->getView()->renderTemplate('pigeon/_cp/widget', [
            'count' => $notifications->inboxCountForStaff(),
            'threads' => $notifications->recentInboxThreads(8),
        ]);
    }

    public function getMaxColspan(): ?int
    {
        return 2;
    }
}
