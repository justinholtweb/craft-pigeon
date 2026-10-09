<?php

namespace justinholtweb\pigeon;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\User;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ThreadType;
use justinholtweb\pigeon\models\Settings;
use justinholtweb\pigeon\services\Inbound;
use justinholtweb\pigeon\services\Messages;
use justinholtweb\pigeon\services\Notifications;
use justinholtweb\pigeon\services\Participants;
use justinholtweb\pigeon\services\Threads;
use justinholtweb\pigeon\variables\PigeonVariable;
use justinholtweb\pigeon\widgets\InboxWidget;
use yii\base\Event;

/**
 * Pigeon — Two-way threaded messaging for Craft CMS.
 *
 * @property Threads $threads
 * @property Messages $messages
 * @property Participants $participants
 * @property Notifications $notifications
 * @property Inbound $inbound
 * @property Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * Read user-to-user ("direct") threads in the control panel. Support threads are the inbox;
     * direct threads are two users' private conversation, so reading the inbox doesn't imply it.
     */
    public const PERMISSION_VIEW_DIRECT = 'pigeon:viewDirectThreads';

    /**
     * Whether `$user` may read `$thread` in the control panel inbox.
     */
    public static function canViewInCp(User $user, Thread $thread): bool
    {
        if (!$user->can('pigeon:accessPlugin')) {
            return false;
        }

        return $thread->type !== ThreadType::Direct->value || $user->can(self::PERMISSION_VIEW_DIRECT);
    }

    public static function config(): array
    {
        return [
            'components' => [
                'threads' => Threads::class,
                'messages' => Messages::class,
                'participants' => Participants::class,
                'notifications' => Notifications::class,
                'inbound' => Inbound::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->onInit(function(): void {
            $this->_registerElementTypes();
            $this->_registerWidgetTypes();
            $this->_registerVariable();
            $this->_registerSiteTemplateRoot();
            $this->_registerCpRoutes();
            $this->_registerSiteRoutes();
            $this->_registerPermissions();
        });
    }

    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        $nav['label'] = Craft::t('pigeon', 'Pigeon');
        $nav['url'] = 'pigeon/threads';

        try {
            $count = $this->notifications->inboxCountForStaff();
            if ($count > 0) {
                $nav['badgeCount'] = $count;
            }
        } catch (\Throwable $e) {
            // Badge is best-effort; never block CP nav rendering.
        }

        $nav['subnav'] = [
            'threads' => ['label' => Craft::t('pigeon', 'Inbox'), 'url' => 'pigeon/threads'],
        ];

        if (Craft::$app->getUser()->getIsAdmin() || Craft::$app->getUser()->checkPermission('pigeon:manageSettings')) {
            $nav['subnav']['settings'] = ['label' => Craft::t('pigeon', 'Settings'), 'url' => 'pigeon/settings'];
        }

        return $nav;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('pigeon/_settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'inboundWebhooks' => $this->inbound->webhookUrls(),
            'imapAvailable' => Inbound::imapAvailable(),
            'inboundRecent' => $this->inbound->recent(10),
        ]);
    }

    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = Thread::class;
            }
        );
    }

    private function _registerWidgetTypes(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = InboxWidget::class;
            }
        );
    }

    private function _registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('pigeon', PigeonVariable::class);
            }
        );
    }

    private function _registerSiteTemplateRoot(): void
    {
        // Plugin templates resolve under "pigeon/…" in the control panel
        // automatically; register the same root for site requests so the
        // front-end controllers can render their templates.
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            static function(RegisterTemplateRootsEvent $event): void {
                $event->roots['pigeon'] = __DIR__ . '/templates';
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['pigeon'] = 'pigeon/admin/index';
                $event->rules['pigeon/threads'] = 'pigeon/admin/index';
                $event->rules['pigeon/threads/<threadId:\d+>'] = 'pigeon/admin/thread';
                $event->rules['pigeon/settings'] = 'pigeon/admin/settings';
            }
        );
    }

    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['pigeon/threads'] = 'pigeon/threads/index';
                $event->rules['pigeon/threads/<threadId:\d+>'] = 'pigeon/threads/view';
                $event->rules['pigeon/t/<token:[^\/]+>'] = 'pigeon/guest/view';
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('pigeon', 'Pigeon'),
                    'permissions' => [
                        'pigeon:accessPlugin' => [
                            'label' => Craft::t('pigeon', 'Access Pigeon'),
                            'nested' => [
                                self::PERMISSION_VIEW_DIRECT => [
                                    'label' => Craft::t('pigeon', 'View private user-to-user conversations'),
                                ],
                            ],
                        ],
                        'pigeon:manageThreads' => [
                            'label' => Craft::t('pigeon', 'Manage threads (view, reply, status)'),
                            'nested' => [
                                'pigeon:assignThreads' => [
                                    'label' => Craft::t('pigeon', 'Assign threads to staff'),
                                ],
                            ],
                        ],
                        'pigeon:manageSettings' => [
                            'label' => Craft::t('pigeon', 'Manage settings'),
                        ],
                    ],
                ];
            }
        );
    }
}
