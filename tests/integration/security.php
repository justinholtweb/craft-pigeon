<?php
/**
 * Pigeon's guest routes, attachments and inbox access — checked in the plugin-testing harness,
 * over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pigeon/tests/integration/security.php
 *
 * Until 5.0.4: the guest rate limit was keyed on address *and* email, so a client changing the
 * email each time was never limited, and every request mailed a link — with a subject the visitor
 * wrote — to whatever address they typed. Attachments were linked by their asset URL, so on a
 * public volume a guest's upload was a public file. Opening a thread in the CP made the reader a
 * participant, and anyone with inbox access could read two users' private conversation. Guest
 * forms redirected to the Referer.
 *
 * Sets Pigeon's attachment volume for the run and puts its settings back afterwards (in a fresh
 * process — see craft-nuke's tests/README). Flushes the harness cache, which holds the budgets.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\Asset;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\helpers\AttachmentHelper;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\records\AttachmentRecord;
use justinholtweb\pigeon\records\MessageRecord;
use justinholtweb\pigeon\records\ParticipantRecord;

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

// craft-penny's broken beforeSaveElement handler (see craft-bird's checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$run = bin2hex(random_bytes(3));
$domain = "pigeon-sec-$run.test";
$password = 'Pigeon-' . bin2hex(random_bytes(6));
$settingsPath = 'plugins.pigeon.settings';
$settingsBefore = Craft::$app->getProjectConfig()->get($settingsPath);
$volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
$volume or throw new RuntimeException('The harness has no volume to attach to.');
$cleanup = ['users' => [], 'threads' => []];

$pc = Craft::$app->getProjectConfig();

// The harness's Bouncer guards this volume for signed-in users only ("Contract documents"), and
// Bouncer filters element queries for anonymous visitors — so every guest download would 404
// for Bouncer's reasons, not Pigeon's, and the refusals below would pass for nothing. Those rules
// are switched off for the run and put back with everything else.
$bouncerRestore = [];
foreach ((array)($pc->get('bouncer.rules') ?? []) as $ruleUid => $rule) {
    if (($rule['enabled'] ?? false) && ($rule['target']['type'] ?? null) === 'assets'
        && (($rule['target']['allSources'] ?? false) || in_array($volume->uid, (array)($rule['target']['sourceUids'] ?? []), true))) {
        $bouncerRestore["bouncer.rules.$ruleUid.enabled"] = true;
        $pc->set("bouncer.rules.$ruleUid.enabled", false, 'Pigeon security.php');
    }
}

$pc->set($settingsPath, array_merge(is_array($settingsBefore) ? $settingsBefore : [], [
    'attachmentVolumeUid' => $volume->uid,
    'allowGuestThreads' => true,
]), 'Pigeon security.php');
$pc->saveModifiedConfigData();
$pc->writeYamlFiles(true);
Craft::$app->getCache()->flush();

register_shutdown_function(function() use (&$cleanup, $domain, $settingsPath, $settingsBefore, $bouncerRestore, $root) {
    // Threads, their assets and the users, in-process — none of that is project config.
    $threadIds = array_unique(array_merge(
        $cleanup['threads'],
        (new Query())->select('id')->from('{{%pigeon_threads}}')->where(['like', 'starterEmail', "@$domain"])->column(),
    ));
    foreach ($threadIds as $threadId) {
        $assetIds = (new Query())->select('a.assetId')->from(['a' => '{{%pigeon_attachments}}'])
            ->innerJoin(['m' => '{{%pigeon_messages}}'], '[[m.id]] = [[a.messageId]]')->where(['m.threadId' => $threadId])->column();
        foreach (Asset::find()->id($assetIds)->status(null)->all() as $asset) {
            Craft::$app->getElements()->deleteElement($asset, true);
        }
        if ($thread = Thread::find()->id($threadId)->status(null)->one()) {
            Craft::$app->getElements()->deleteElement($thread, true);
        }
    }
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
    Craft::$app->getCache()->flush();

    $restore = sys_get_temp_dir() . '/pigeon-restore-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($restore, '<?php
require ' . var_export($root . '/bootstrap.php', true) . ';
$app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
$pc = Craft::$app->getProjectConfig();
$pc->set(' . var_export($settingsPath, true) . ', ' . var_export($settingsBefore, true) . ', "Restore Pigeon settings after security.php");
foreach (' . var_export($bouncerRestore, true) . ' as $path => $value) {
    $pc->set($path, $value, "Restore Bouncer rule after Pigeon security.php");
}
$pc->saveModifiedConfigData();
$pc->writeYamlFiles(true);
');
    exec('php ' . escapeshellarg($restore) . ' 2>&1', $out, $code);
    unlink($restore);
    $code === 0 or print("  ! Could not restore Pigeon's settings: " . implode("\n", $out) . "\n");
});

$user = static function(string $name, array $permissions, bool $admin = false) use (&$cleanup, $domain, $password): User {
    $user = new User(['username' => "$name-" . substr($domain, 11, 6), 'email' => "$name@$domain", 'newPassword' => $password, 'admin' => $admin]);
    Craft::$app->getElements()->saveElement($user, false) or throw new RuntimeException(json_encode($user->getErrors()));
    Craft::$app->getUsers()->activateUser($user);
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
    $cleanup['users'][] = $user;

    return $user;
};

$staff = $user('staff', ['accesscp', 'accessplugin-pigeon', 'pigeon:accessplugin', 'pigeon:managethreads']);
$noteAuthor = $user('noteauthor', ['accesscp', 'pigeon:accessplugin', 'pigeon:managethreads']);
$staffDirect = $user('staffdirect', ['accesscp', 'accessplugin-pigeon', 'pigeon:accessplugin', 'pigeon:viewdirectthreads', 'pigeon:managethreads']);
$alice = $user('alice', []);
$bob = $user('bob', []);

$direct = $plugin->threads->createDirectThread("Private $run", $alice->id, [$bob->id]);
$plugin->messages->post($direct, ['body' => "Just between us $run", 'authorUserId' => $alice->id, 'authorName' => 'Alice']);
$cleanup['threads'][] = $direct->id;

function client(?string $username = null, ?string $password = null): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');

    if ($username !== null) {
        $http->post('index.php?p=actions/users/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
            or throw new RuntimeException("Could not sign in as $username");
    }

    return [$http, $csrf];
}

/** Starts a guest thread the way the contact form does. Returns [status, location]. */
$startGuest = static function(array $fields, array $files = [], array $headers = []): array {
    [$http, $csrf] = client();
    $multipart = [['name' => 'action', 'contents' => 'pigeon/guest/start'], ['name' => 'CRAFT_CSRF_TOKEN', 'contents' => $csrf()]];
    foreach ($fields as $name => $value) {
        $multipart[] = ['name' => $name, 'contents' => $value];
    }
    foreach ($files as $name => $contents) {
        $multipart[] = ['name' => 'attachments[]', 'contents' => $contents, 'filename' => $name];
    }
    $response = $http->post('index.php?p=contact', ['multipart' => $multipart, 'headers' => $headers]);

    return [$response->getStatusCode(), $response->getHeaderLine('Location')];
};

$mailTo = static function(string $email): ?array {
    $list = json_decode((string)(new Client(['http_errors' => false]))->get('http://localhost:8025/api/v1/search', ['query' => ['query' => "to:$email"]])->getBody(), true);
    $id = $list['messages'][0]['ID'] ?? null;

    return $id ? json_decode((string)(new Client())->get("http://localhost:8025/api/v1/message/$id")->getBody(), true) : null;
};

echo "\nA guest starts a thread\n";

$guestEmail = "guest@$domain";
$lure = "Your account is suspended — verify at evil.example $run";
[$status, $location] = $startGuest(['email' => $guestEmail, 'name' => 'Guest', 'subject' => $lure, 'body' => 'Hello'], ["proof-$run.txt" => "secret contents $run"]);
$token = preg_match('#pigeon/t/([^/?]+)#', $location, $m) ? $m[1] : null;
$guestThread = Thread::find()->status(null)->andWhere(['pigeon_threads.starterEmail' => $guestEmail])->one();

check('it lands on its private page', function() use ($status, $token) {
    return $status === 302 && $token !== null ?: "status $status";
});

check('the email it sends doesn’t carry the subject the visitor typed', function() use ($mailTo, $guestEmail, $lure) {
    $mail = $mailTo($guestEmail);
    if ($mail === null) {
        return 'no email reached Mailpit';
    }
    $all = ($mail['Subject'] ?? '') . ' ' . ($mail['Text'] ?? '') . ' ' . ($mail['HTML'] ?? '');

    return !str_contains($all, $lure) && !str_contains($all, 'evil.example') && str_contains($mail['Subject'] ?? '', 'Your conversation with')
        ?: 'subject: ' . ($mail['Subject'] ?? '?');
});

$attachment = AttachmentRecord::find()->innerJoin(['m' => '{{%pigeon_messages}}'], '[[m.id]] = [[pigeon_attachments.messageId]]')
    ->where(['m.threadId' => $guestThread?->id])->one();
$asset = $attachment ? Asset::find()->id($attachment->assetId)->status(null)->one() : null;

check('its upload is stored in a folder of the thread’s own, not the volume root', function() use ($asset, $guestThread) {
    return $asset && $guestThread && $asset->getFolder()->path === AttachmentHelper::FOLDER . '/' . $guestThread->uid . '/'
        ?: 'folder ' . ($asset?->getFolder()->path ?? 'none');
});

echo "\nWho can download it\n";

$download = static function(int $id, ?string $token = null, ?Client $http = null, bool $cp = false) {
    $http ??= new Client(['base_uri' => 'http://localhost/', 'http_errors' => false, 'allow_redirects' => false]);
    $query = ['id' => $id] + ($token !== null ? ['access' => $token] : []);

    return $http->get(($cp ? 'index.php?p=admin/actions/' : 'index.php?p=actions/') . 'pigeon/attachments/download&' . http_build_query($query));
};

check('the guest, with their link', function() use ($download, $attachment, $token, $run) {
    $response = $download((int)$attachment->id, $token);

    return $response->getStatusCode() === 200 && (string)$response->getBody() === "secret contents $run"
        && str_starts_with($response->getHeaderLine('Content-Disposition'), 'attachment')
        ?: 'status ' . $response->getStatusCode() . ' ' . $response->getHeaderLine('Content-Disposition');
});

check('not a stranger without it', function() use ($download, $attachment) {
    return $download((int)$attachment->id)->getStatusCode() === 404 ?: 'status ' . $download((int)$attachment->id)->getStatusCode();
});

check('not a guest holding another thread’s link', function() use ($download, $attachment, $plugin, $domain) {
    $other = $plugin->threads->createSupportThread('Other', "other@$domain", 'Other');
    $participant = ParticipantRecord::findOne(['threadId' => $other->id, 'userId' => null]);
    $otherToken = $plugin->participants->mintToken($participant);

    return $download((int)$attachment->id, $otherToken)->getStatusCode() === 404 ?: 'downloaded';
});

[$staffHttp, $staffCsrf] = client($staff->username, $password);

check('staff, from the inbox', function() use ($download, $attachment, $staffHttp) {
    $status = $download((int)$attachment->id, null, $staffHttp, true)->getStatusCode();

    return $status === 200 ?: "status $status";
});

check('a guest can’t download an attachment on an internal note', function() use ($plugin, $guestThread, $noteAuthor, $volume, $download, $token, $run) {
    $file = tempnam(sys_get_temp_dir(), 'pg');
    file_put_contents($file, "note $run");
    $asset = new Asset(['tempFilePath' => $file, 'newFolderId' => Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(AttachmentHelper::FOLDER . '/' . $guestThread->uid, $volume)->id, 'avoidFilenameConflicts' => true]);
    $asset->setFilename("note-$run.txt");
    $asset->setVolumeId($volume->id);
    $asset->setScenario(Asset::SCENARIO_CREATE);
    Craft::$app->getElements()->saveElement($asset) or throw new RuntimeException(json_encode($asset->getErrors()));
    $note = $plugin->messages->post($guestThread, ['body' => 'staff only', 'authorUserId' => $noteAuthor->id, 'authorName' => 'Staff', 'isInternalNote' => true, 'attachmentAssetIds' => [$asset->id]]);
    $noteAttachment = AttachmentRecord::findOne(['messageId' => $note->id]);

    return $download((int)$noteAttachment->id, $token)->getStatusCode() === 404 ?: 'downloaded';
});

check('pages link attachments through the checked download, never the asset URL', function() use ($token, $asset) {
    $html = (string)(new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]))->get("index.php?p=pigeon/t/$token")->getBody();

    return str_contains($html, 'pigeon/attachments/download') && !str_contains($html, (string)$asset->getUrl()) ?: 'asset URL on the page';
});

echo "\nThe inbox\n";

$cp = static fn(Client $http, string $uri) => $http->get("index.php?p=admin/$uri");

check('opening a support thread doesn’t make the reader a participant', function() use ($cp, $staffHttp, $guestThread, $staff) {
    $status = $cp($staffHttp, "pigeon/threads/{$guestThread->id}")->getStatusCode();
    $joined = ParticipantRecord::find()->where(['threadId' => $guestThread->id, 'userId' => $staff->id])->exists();

    return $status === 200 && !$joined ?: "status $status, joined " . var_export($joined, true);
});

check('inbox access alone can’t read two users’ private conversation', function() use ($cp, $staffHttp, $direct) {
    $status = $cp($staffHttp, "pigeon/threads/{$direct->id}")->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('…or change it', function() use ($staffHttp, $staffCsrf, $direct) {
    $status = $staffHttp->post('index.php?p=admin/actions/pigeon/admin/status', ['form_params' => ['threadId' => $direct->id, 'status' => 'closed', 'CRAFT_CSRF_TOKEN' => $staffCsrf()]])->getStatusCode();

    return $status === 403 && Thread::find()->id($direct->id)->status(null)->one()->threadStatus !== 'closed' ?: "status $status";
});

$listed = static function(Client $http, callable $csrf, string $source, array $criteria = []) use ($run): string {
    $response = $http->post('index.php?p=admin/actions/element-indexes/get-elements', [
        'headers' => ['Accept' => 'application/json'],
        'form_params' => ['elementType' => Thread::class, 'source' => $source, 'context' => 'index', 'viewState' => ['mode' => 'table', 'static' => false], 'criteria' => $criteria, 'CRAFT_CSRF_TOKEN' => $csrf()],
    ]);
    $response->getStatusCode() === 200 or throw new RuntimeException('element index answered ' . $response->getStatusCode());

    return (string)(json_decode((string)$response->getBody(), true)['html'] ?? '');
};

check('…or see it listed in the inbox — which still lists support threads', function() use ($listed, $staffHttp, $staffCsrf, $run) {
    $html = $listed($staffHttp, $staffCsrf, '*');
    $at = strpos($html, "Private $run");

    return $at === false && str_contains($html, "evil.example $run") ?: json_encode(['direct' => $at !== false, 'support' => str_contains($html, "evil.example $run")]);
});

check('…even asking for direct threads by name', function() use ($listed, $staffHttp, $staffCsrf, $run) {
    // Craft applies a source's criteria in the browser; a request is free to send its own.
    return !str_contains($listed($staffHttp, $staffCsrf, '*', ['type' => 'direct']), "Private $run") ?: 'listed';
});

[$directHttp, $directCsrf] = client($staffDirect->username, $password);

check('with the direct-thread permission, staff can read and list it', function() use ($cp, $directHttp, $directCsrf, $direct, $listed, $run) {
    $status = $cp($directHttp, "pigeon/threads/{$direct->id}")->getStatusCode();

    return $status === 200 && str_contains($listed($directHttp, $directCsrf, '*'), "Private $run") ?: "status $status";
});

check('the participants themselves still can, on the front end', function() use ($alice, $password, $direct, $run) {
    [$http] = client($alice->username, $password);
    $html = (string)$http->get("index.php?p=pigeon/threads/{$direct->id}")->getBody();

    return str_contains($html, "Just between us $run") ?: 'not shown';
});

echo "\nRedirects\n";

check('a refused guest form goes back to the page it was on, not to the Referer', function() use ($startGuest) {
    [$status, $location] = $startGuest(['email' => 'not-an-email', 'body' => 'x'], [], ['Referer' => 'https://evil.example/phish']);

    return $status === 302 && !str_contains($location, 'evil.example') && str_contains($location, 'contact') ?: "status $status → $location";
});

check('…and so does the honeypot', function() use ($startGuest) {
    [$status, $location] = $startGuest(['email' => 'bot@example.test', 'body' => 'x', 'pigeon_hp' => 'filled'], [], ['Referer' => 'https://evil.example/phish']);

    return $status === 302 && !str_contains($location, 'evil.example') ?: "status $status → $location";
});

echo "\nSettings\n";

check('the settings screen warns that the attachment volume has public URLs', function() use ($volume) {
    if (!($volume->getFs()->hasUrls ?? false)) {
        return true; // nothing to warn about in this harness
    }
    [$admin] = client('admin', 'claudepassword');
    $html = (string)$admin->get('index.php?p=admin/settings/plugins/pigeon')->getBody();

    return str_contains($html, 'This volume has public URLs') ?: 'no warning';
});

echo "\nThe guest rate limit\n";

check('changing the email every time doesn’t buy a fresh budget', function() use ($startGuest, $domain, $plugin) {
    Craft::$app->getCache()->flush();
    $max = $plugin->getSettings()->rateLimitMaxMessages;
    $accepted = 0;
    for ($i = 0; $i < $max + 3; $i++) {
        [, $location] = $startGuest(['email' => "spray$i@$domain", 'body' => 'x', 'subject' => 'x']);
        $accepted += str_contains($location, 'pigeon/t/') ? 1 : 0;
    }
    $threads = (int)Thread::find()->status(null)->andWhere(['like', 'pigeon_threads.starterEmail', "spray%@$domain", false])->count();

    return $accepted === $max && $threads === $max ?: "$accepted accepted, $threads threads, limit $max";
});

check('one inbox can’t be flooded from many addresses: each recipient has a budget of its own', function() use ($startGuest, $domain, $plugin) {
    Craft::$app->getCache()->flush();
    $victim = "victim@$domain";
    $settings = $plugin->getSettings();
    // Spend the recipient's budget the way many other clients would have.
    for ($i = 0; $i < $settings->rateLimitMaxMessages; $i++) {
        \justinholtweb\pigeon\helpers\RateLimit::allowForWindow('guest-mail-to', $victim, $settings->rateLimitMaxMessages, $settings->rateLimitWindowSeconds);
    }
    [, $location] = $startGuest(['email' => $victim, 'body' => 'x', 'subject' => 'x']);
    $threads = (int)Thread::find()->status(null)->andWhere(['pigeon_threads.starterEmail' => $victim])->count();

    return !str_contains($location, 'pigeon/t/') && $threads === 0 ?: "accepted, $threads threads";
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
