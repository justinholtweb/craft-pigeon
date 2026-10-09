<?php

namespace justinholtweb\pigeon\controllers;

use craft\web\Controller;
use justinholtweb\pigeon\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The reply-by-email webhooks: `/actions/pigeon/inbound/postmark`, `…/mailgun`, `…/sendgrid`.
 *
 * Anonymous and without CSRF, because the caller is a mail provider, not a browser — and therefore
 * **never** without the provider's own proof: every delivery is verified (signature or
 * credentials, in constant time) by {@see \justinholtweb\pigeon\services\Inbound::receive()}
 * before a byte of it is parsed, and a provider with no secret configured is refused outright.
 *
 * The answer comes back as soon as the email is stored; posting it to the conversation happens in
 * a queue job, so a slow database never looks like a failed delivery to the provider.
 */
class InboundController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    /** Provider-signed, not browser-submitted: there is no CSRF token to check. */
    public $enableCsrfValidation = false;

    public function actionPostmark(): Response
    {
        return $this->receive('postmark');
    }

    public function actionMailgun(): Response
    {
        return $this->receive('mailgun');
    }

    public function actionSendgrid(): Response
    {
        return $this->receive('sendgrid');
    }

    private function receive(string $provider): Response
    {
        $this->requirePostRequest();

        $inbound = Plugin::getInstance()->inbound;

        // Off: the endpoint does not exist, as far as anybody outside can tell.
        if (!$inbound->isEnabled()) {
            throw new NotFoundHttpException();
        }

        $result = $inbound->receive($provider, $inbound->requestFromCraft($this->request));

        $response = $this->asJson([
            'ok' => $result['status'] === 200,
            'result' => $result['result'],
        ]);
        $response->setStatusCode($result['status']);

        return $response;
    }
}
