<?php
/**
 * Pigeon reply by email over real HTTP and the real console: the webhook endpoints' security, and
 * `php craft pigeon/inbound/*`.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pigeon/tests/integration/inbound-http.php
 *
 * The web request and the console command read settings from project config, so this — unlike
 * `inbound.php` — has to persist them: reply by email on, a reply mailbox and Postmark credentials
 * (Mailgun and SendGrid deliberately left unset, to prove an unconfigured provider is refused).
 * The original settings are restored in a **fresh PHP process** at the end, because once a web
 * request has seen the change this process's copy of project config is stale.
 *
 * SendGrid's signed mode needs `enable_post_data_reading = Off` on the webhook route, which the
 * harness's PHP-FPM pool doesn't set; its signature is checked in `inbound.php` instead.
 */

$root = '/var/www/html';
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

require __DIR__ . '/_inbound-fixtures.php';

use craft\db\Query;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\records\MessageRecord;
use justinholtweb\pigeon\records\ParticipantRecord;
use justinholtweb\pigeon\services\Inbound;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$run = bin2hex(random_bytes(3));
$guestEmail = "gina@pigeon-http-$run.test";
$inboundFloor = (int)(new Query())->from([Inbound::TABLE_INBOUND])->max('id');
$queueFloor = (int)(new Query())->from('{{%queue}}')->max('id');
$settingsPath = 'plugins.pigeon.settings';
$original = Craft::$app->getProjectConfig()->get($settingsPath) ?? [];
$stash = sys_get_temp_dir() . "/pigeon-inbound-http-$run.json";
file_put_contents($stash, json_encode($original));
$eml = sys_get_temp_dir() . "/pigeon-inbound-http-$run.eml";

$persist = static function(array $changes) use ($plugin): void {
    Craft::$app->getPlugins()->savePluginSettings($plugin, array_merge($plugin->getSettings()->toArray(), $changes));
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    Craft::$app->getProjectConfig()->writeYamlFiles(true);
};

$client = Craft::createGuzzleClient([
    'base_uri' => 'http://localhost/',
    'http_errors' => false,
    'allow_redirects' => false,
    'timeout' => 60,
]);

$post = static fn(string $provider, string $body, array $options = []) => $client->post('index.php?p=actions/pigeon/inbound/' . $provider, array_merge([
    'headers' => ['Content-Type' => 'application/json'],
    'body' => $body,
], $options));

$craft = static function(string $command): array {
    exec('cd /var/www/html && ' . $command . ' 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
};

$thread = $plugin->threads->createSupportThread("HTTP $run", $guestEmail, 'Gina');
$plugin->messages->post($thread, ['body' => 'Hello over HTTP', 'authorEmail' => $guestEmail, 'authorName' => 'Gina', 'notify' => false]);
$guest = ParticipantRecord::findOne(['threadId' => $thread->id, 'userId' => null]);
$plugin->participants->mintToken($guest);
$replyTo = 'messages+' . $guest->replyToken . '@pigeon.example.com';

$toGuestThread = static fn(array $fields) => postmarkPayload(array_merge([
    'From' => $guestEmail,
    'FromFull' => ['Email' => $guestEmail, 'Name' => 'Gina'],
    'To' => $replyTo,
    'ToFull' => [['Email' => $replyTo]],
], $fields));

try {
    $persist([
        'inboundEnabled' => true,
        'inboundAddress' => FIXTURE_DESK,
        'postmarkUsername' => FIXTURE_POSTMARK_USER,
        'postmarkPassword' => FIXTURE_POSTMARK_PASSWORD,
        'mailgunSigningKey' => '',
        'sendgridPublicKey' => '',
        'sendgridUsername' => '',
        'sendgridPassword' => '',
        'inboundRateLimit' => 0,
    ]);

    echo "\nThe webhook endpoints\n";

    check('a correctly authenticated Postmark post is accepted — anonymous, no CSRF token — and stored', function() use ($post, $toGuestThread, $run) {
        $response = $post('postmark', $toGuestThread(['Subject' => "Over HTTP $run", 'TextBody' => "Reply over HTTP $run"]), ['auth' => [FIXTURE_POSTMARK_USER, FIXTURE_POSTMARK_PASSWORD]]);
        $row = (new Query())->from([Inbound::TABLE_INBOUND])->where(['subject' => "Over HTTP $run"])->one();

        return $response->getStatusCode() === 200 && $row && json_decode((string)$response->getBody(), true)['result'] === 'accepted'
            ?: 'HTTP ' . $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 300);
    });

    check('no credentials: 401, nothing stored', function() use ($post, $toGuestThread, $run) {
        $response = $post('postmark', $toGuestThread(['Subject' => "No creds $run"]));
        $stored = (new Query())->from([Inbound::TABLE_INBOUND])->where(['subject' => "No creds $run"])->exists();

        return $response->getStatusCode() === 401 && !$stored ?: 'HTTP ' . $response->getStatusCode();
    });

    check('wrong password: 401, nothing stored', function() use ($post, $toGuestThread, $run) {
        $response = $post('postmark', $toGuestThread(['Subject' => "Bad creds $run"]), ['auth' => [FIXTURE_POSTMARK_USER, 'guess']]);
        $stored = (new Query())->from([Inbound::TABLE_INBOUND])->where(['subject' => "Bad creds $run"])->exists();

        return $response->getStatusCode() === 401 && !$stored ?: 'HTTP ' . $response->getStatusCode();
    });

    check('Mailgun with no signing key configured: 403, even with a "signed" post', function() use ($client) {
        $request = mailgunRequest(['subject' => 'Unconfigured'], key: '');
        $response = $client->post('index.php?p=actions/pigeon/inbound/mailgun', ['form_params' => $request->post]);

        return $response->getStatusCode() === 403 ?: 'HTTP ' . $response->getStatusCode();
    });

    check('SendGrid with nothing configured: 403', function() use ($client) {
        $response = $client->post('index.php?p=actions/pigeon/inbound/sendgrid', ['form_params' => ['from' => 'x@example.org', 'text' => 'hi']]);

        return $response->getStatusCode() === 403 ?: 'HTTP ' . $response->getStatusCode();
    });

    check('GET is refused', function() use ($client) {
        $code = $client->get('index.php?p=actions/pigeon/inbound/postmark')->getStatusCode();

        return in_array($code, [400, 405], true) ?: "HTTP $code";
    });

    check('an unknown provider action is refused (no such action)', function() use ($post) {
        $code = $post('sparkpost', '{}', ['auth' => [FIXTURE_POSTMARK_USER, FIXTURE_POSTMARK_PASSWORD]])->getStatusCode();

        // 404 from Craft; the harness's craft-friends turns a front-end 404 into a 500 (see the harness notes).
        return in_array($code, [404, 500], true) ?: "HTTP $code";
    });

    echo "\nThe settings screen\n";

    check('renders the Reply by email section with each provider’s webhook URL (admin, over HTTP)', function() {
        $http = Craft::createGuzzleClient(['base_uri' => 'http://localhost/', 'cookies' => new GuzzleHttp\Cookie\CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
        $csrf = (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
        $login = $http->post('index.php?p=actions/users/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['loginName' => 'admin', 'password' => 'claudepassword', 'CRAFT_CSRF_TOKEN' => $csrf]]);

        if ($login->getStatusCode() !== 200) {
            return 'login HTTP ' . $login->getStatusCode();
        }

        $page = $http->get('index.php?p=admin/settings/plugins/pigeon');
        $html = (string)$page->getBody();

        return $page->getStatusCode() === 200 && str_contains($html, 'Reply by email') && str_contains($html, 'pigeon/inbound/postmark')
            && str_contains($html, 'pigeon/inbound/sendgrid') && str_contains($html, 'The last emails received')
            ?: 'HTTP ' . $page->getStatusCode();
    });

    echo "\nThe console\n";

    check('pigeon/inbound/process posts the reply the webhook queued', function() use ($craft, $thread, $run) {
        [$code, $out] = $craft('php craft pigeon/inbound/process');
        $row = (new Query())->from([Inbound::TABLE_INBOUND])->where(['subject' => "Over HTTP $run"])->one();
        $posted = MessageRecord::find()->where(['threadId' => $thread->id, 'body' => "Reply over HTTP $run"])->exists();

        return $code === 0 && in_array($row['status'], ['reply'], true) && $posted ?: "exit $code: $out / " . ($row['status'] ?? '?') . ' ' . ($row['reason'] ?? '');
    });

    $raw = strtr((string)file_get_contents(dirname(__DIR__) . '/fixtures/inbound/html-reply.eml'), [
        '{{TOKEN}}' => $guest->replyToken, '{{FROM}}' => $guestEmail, '{{MSGID}}' => "stdin-$run@example.net",
    ]);
    file_put_contents($eml, $raw);

    check('pigeon/inbound/import - --sync reads a raw message from standard input and posts it', function() use ($craft, $eml, $thread) {
        [$code, $out] = $craft('php craft pigeon/inbound/import - --sync < ' . escapeshellarg($eml));
        $posted = MessageRecord::find()->where(['threadId' => $thread->id])->andWhere(['like', 'body', 'reset link (https://example.net/reset) fails'])->exists();

        return $code === 0 && str_contains($out, 'accepted') && $posted ?: "exit $code: $out";
    });

    check('the same message imported again (from a file) reports a duplicate', function() use ($craft, $eml) {
        [$code, $out] = $craft('php craft pigeon/inbound/import ' . escapeshellarg($eml) . ' --sync');

        return $code === 0 && str_contains($out, 'duplicate') ?: "exit $code: $out";
    });

    check('an empty import is refused with NOINPUT (66)', function() use ($craft) {
        [$code] = $craft('php craft pigeon/inbound/import - < /dev/null');

        return $code === 66 ?: "exit $code";
    });

    check('pigeon/inbound/poll without the imap extension says so and exits 78', function() use ($craft) {
        [$code, $out] = $craft('php craft pigeon/inbound/poll');

        if (function_exists('imap_open')) {
            return true;
        }

        return $code === 78 && str_contains($out, 'imap extension') ?: "exit $code: $out";
    });

    check('pigeon/inbound/log lists what arrived', function() use ($craft, $guestEmail) {
        [$code, $out] = $craft('php craft pigeon/inbound/log --limit=5');

        return $code === 0 && str_contains($out, $guestEmail) && str_contains($out, 'reply') ?: "exit $code: $out";
    });

    check('pigeon/inbound/prune keeps recent rows', function() use ($craft, $run) {
        [$code, $out] = $craft('php craft pigeon/inbound/prune --days=30');
        $kept = (new Query())->from([Inbound::TABLE_INBOUND])->where(['subject' => "Over HTTP $run"])->exists();

        return $code === 0 && $kept ?: "exit $code: $out";
    });

    echo "\nSwitched off\n";

    $persist(['inboundEnabled' => false]);

    check('with reply by email off, the endpoint is a 404 even with good credentials', function() use ($post, $toGuestThread) {
        $code = $post('postmark', $toGuestThread(['Subject' => 'While off']), ['auth' => [FIXTURE_POSTMARK_USER, FIXTURE_POSTMARK_PASSWORD]])->getStatusCode();

        return in_array($code, [404, 500], true) && !(new Query())->from([Inbound::TABLE_INBOUND])->where(['subject' => 'While off'])->exists() ?: "HTTP $code";
    });

    check('…and the console refuses with a configuration exit code (78)', function() use ($craft, $eml) {
        [$code] = $craft('php craft pigeon/inbound/import ' . escapeshellarg($eml));

        return $code === 78 ?: "exit $code";
    });
} finally {
    echo "\nCleaning up\n";

    if ($t = Thread::find()->id($thread->id)->status(null)->one()) {
        Craft::$app->getElements()->deleteElement($t, true);
    }

    Craft::$app->getDb()->createCommand()->delete(Inbound::TABLE_INBOUND, ['>', 'id', $inboundFloor])->execute();
    Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['>', 'id', $queueFloor])->execute();
    @unlink($eml);

    // A fresh process: this one's project config went stale the moment a web request read it.
    $restore = sys_get_temp_dir() . "/pigeon-inbound-http-restore-$run.php";
    file_put_contents($restore, <<<'PHP'
<?php
require '/var/www/html/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
$settings = json_decode(file_get_contents($argv[1]), true);
$config = Craft::$app->getProjectConfig();
$config->set('plugins.pigeon.settings', $settings, 'Restore Pigeon settings after inbound-http.php');
$config->saveModifiedConfigData();
$config->writeYamlFiles(true);
echo "  settings restored\n";
PHP);
    passthru(PHP_BINARY . ' ' . escapeshellarg($restore) . ' ' . escapeshellarg($stash));
    @unlink($restore);
    @unlink($stash);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
