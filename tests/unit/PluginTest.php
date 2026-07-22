<?php

namespace justinholtweb\pigeontests\unit;

use Craft;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\models\Settings;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\services\Messages;
use justinholtweb\pigeon\services\Notifications;
use justinholtweb\pigeon\services\Participants;
use justinholtweb\pigeon\services\Threads;
use justinholtweb\pigeon\widgets\InboxWidget;

class PluginTest extends PigeonTestCase
{
    public function testPluginIsInstalled(): void
    {
        self::assertInstanceOf(Plugin::class, Craft::$app->getPlugins()->getPlugin('pigeon'));
    }

    public function testServiceComponentsAreWiredUp(): void
    {
        self::assertInstanceOf(Threads::class, $this->plugin()->threads);
        self::assertInstanceOf(Messages::class, $this->plugin()->messages);
        self::assertInstanceOf(Participants::class, $this->plugin()->participants);
        self::assertInstanceOf(Notifications::class, $this->plugin()->notifications);
    }

    public function testSettingsModel(): void
    {
        self::assertInstanceOf(Settings::class, $this->plugin()->getSettings());
        self::assertTrue($this->plugin()->hasCpSettings);
        self::assertTrue($this->plugin()->hasCpSection);
    }

    public function testThreadElementTypeIsRegistered(): void
    {
        self::assertContains(Thread::class, Craft::$app->getElements()->getAllElementTypes());
    }

    public function testInboxWidgetIsRegistered(): void
    {
        self::assertContains(InboxWidget::class, Craft::$app->getDashboard()->getAllWidgetTypes());
    }

    public function testPermissionsAreRegistered(): void
    {
        $permissions = [];
        foreach (Craft::$app->getUserPermissions()->getAllPermissions() as $group) {
            foreach ($group['permissions'] ?? [] as $name => $config) {
                $permissions[] = $name;
                foreach (array_keys($config['nested'] ?? []) as $nested) {
                    $permissions[] = $nested;
                }
            }
        }

        self::assertContains('pigeon:accessPlugin', $permissions);
        self::assertContains('pigeon:manageThreads', $permissions);
        self::assertContains('pigeon:assignThreads', $permissions);
        self::assertContains('pigeon:manageSettings', $permissions);
    }

    public function testCpNavItemLinksToTheInbox(): void
    {
        $nav = $this->plugin()->getCpNavItem();

        self::assertSame('Pigeon', $nav['label']);
        self::assertSame('pigeon/threads', $nav['url']);
        self::assertArrayHasKey('threads', $nav['subnav']);
    }

    public function testCpNavBadgeCountsPendingSupportThreads(): void
    {
        $admin = $this->createUser('nav-admin@example.test', true);
        Craft::$app->getUser()->setIdentity($admin);

        try {
            $this->createGuestThread('nav@example.test');
            $nav = $this->plugin()->getCpNavItem();

            self::assertArrayHasKey('badgeCount', $nav);
            self::assertGreaterThan(0, $nav['badgeCount']);
            self::assertArrayHasKey('settings', $nav['subnav'], 'Admins can manage settings');
        } finally {
            Craft::$app->getUser()->setIdentity(null);
        }
    }

    public function testSiteTemplateRootIsRegistered(): void
    {
        $view = Craft::$app->getView();
        $event = new \craft\events\RegisterTemplateRootsEvent(['roots' => []]);
        \yii\base\Event::trigger(\craft\web\View::class, \craft\web\View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, $event);

        self::assertArrayHasKey('pigeon', $event->roots);
        self::assertDirectoryExists($event->roots['pigeon']);
        self::assertNotNull($view);
    }

    public function testCpAndSiteRoutesAreRegistered(): void
    {
        $cpEvent = new \craft\events\RegisterUrlRulesEvent(['rules' => []]);
        \yii\base\Event::trigger(\craft\web\UrlManager::class, \craft\web\UrlManager::EVENT_REGISTER_CP_URL_RULES, $cpEvent);

        self::assertSame('pigeon/admin/index', $cpEvent->rules['pigeon/threads']);
        self::assertSame('pigeon/admin/thread', $cpEvent->rules['pigeon/threads/<threadId:\d+>']);
        self::assertSame('pigeon/admin/settings', $cpEvent->rules['pigeon/settings']);

        $siteEvent = new \craft\events\RegisterUrlRulesEvent(['rules' => []]);
        \yii\base\Event::trigger(\craft\web\UrlManager::class, \craft\web\UrlManager::EVENT_REGISTER_SITE_URL_RULES, $siteEvent);

        self::assertSame('pigeon/threads/index', $siteEvent->rules['pigeon/threads']);
        self::assertSame('pigeon/threads/view', $siteEvent->rules['pigeon/threads/<threadId:\d+>']);
        self::assertSame('pigeon/guest/view', $siteEvent->rules['pigeon/t/<token:[^\/]+>']);
    }

    public function testCraftVariableExposesPigeon(): void
    {
        $variable = new \craft\web\twig\variables\CraftVariable();

        self::assertInstanceOf(
            \justinholtweb\pigeon\variables\PigeonVariable::class,
            $variable->get('pigeon'),
        );
    }
}
