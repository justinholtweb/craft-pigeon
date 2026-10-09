<?php

namespace justinholtweb\pigeon\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\helpers\AttachmentHelper;
use justinholtweb\pigeon\helpers\RateLimit;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\records\ParticipantRecord;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Public, token-authenticated access for guests (no Craft account).
 */
class GuestController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    /**
     * View a thread via a guest access token.
     */
    public function actionView(string $token): Response
    {
        $participant = Plugin::getInstance()->participants->findByToken($token);
        if (!$participant) {
            return $this->renderTemplate('pigeon/_front/expired', []);
        }

        $thread = Plugin::getInstance()->threads->getById($participant->threadId);
        if (!$thread) {
            return $this->renderTemplate('pigeon/_front/expired', []);
        }

        // Guests never see internal notes.
        $messages = Plugin::getInstance()->messages->getForThread($thread->id, includeInternal: false);
        Plugin::getInstance()->participants->markRead($participant);

        return $this->renderTemplate('pigeon/_front/guest', [
            'thread' => $thread,
            'messages' => $messages,
            'token' => $token,
            'participant' => $participant,
        ]);
    }

    /**
     * Guest posts a reply to their own thread.
     */
    public function actionReply(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        if ($this->_isHoneypotTripped()) {
            // Silently pretend success.
            return $this->_back();
        }

        $token = (string)$request->getRequiredBodyParam('token');
        $participant = Plugin::getInstance()->participants->findByToken($token);
        if (!$participant) {
            throw new BadRequestHttpException('Invalid or expired link.');
        }

        if (!RateLimit::allowWindow('guest-reply', ...$this->_budget()) || !RateLimit::allowForWindow('guest-reply', (string)$participant->id, ...$this->_budget())) {
            Craft::$app->getSession()->setError(Craft::t('pigeon', 'You are sending messages too quickly. Please wait a moment.'));
            return $this->redirect("pigeon/t/{$token}");
        }

        $thread = Plugin::getInstance()->threads->getById($participant->threadId);
        if (!$thread) {
            throw new BadRequestHttpException('Thread not found.');
        }

        $body = trim((string)$request->getBodyParam('body'));
        $assetIds = AttachmentHelper::saveUploads(UploadedFile::getInstancesByName('attachments'), $thread);

        if ($body === '' && !$assetIds) {
            Craft::$app->getSession()->setError(Craft::t('pigeon', 'Your message cannot be empty.'));
            return $this->redirect("pigeon/t/{$token}");
        }

        // Reopen a closed thread when the guest writes back.
        if ($thread->threadStatus === ThreadStatus::Closed->value) {
            Plugin::getInstance()->threads->setStatus($thread, ThreadStatus::Pending);
        }

        Plugin::getInstance()->messages->post($thread, [
            'body' => $body,
            'authorEmail' => $participant->email,
            'authorName' => $participant->name,
            'attachmentAssetIds' => $assetIds,
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('pigeon', 'Your reply was sent.'));

        return $this->redirect("pigeon/t/{$token}");
    }

    /**
     * Guest starts a new support thread (from a public contact form).
     */
    public function actionStart(): ?Response
    {
        $this->requirePostRequest();

        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->allowGuestThreads) {
            throw new BadRequestHttpException('Guest threads are disabled.');
        }

        $request = Craft::$app->getRequest();
        if ($this->_isHoneypotTripped()) {
            return $this->_back();
        }

        $email = trim((string)$request->getBodyParam('email'));
        $name = trim((string)$request->getBodyParam('name')) ?: null;
        $subject = trim((string)$request->getBodyParam('subject'));
        $body = trim((string)$request->getBodyParam('body'));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Craft::$app->getSession()->setError(Craft::t('pigeon', 'A valid email address is required.'));
            return $this->_back();
        }

        if ($body === '') {
            Craft::$app->getSession()->setError(Craft::t('pigeon', 'Your message cannot be empty.'));
            return $this->_back();
        }

        if (!$this->_allowMail($email)) {
            Craft::$app->getSession()->setError(Craft::t('pigeon', 'You are sending messages too quickly. Please wait a moment.'));
            return $this->_back();
        }

        $threadsService = Plugin::getInstance()->threads;
        $thread = $threadsService->createSupportThread($subject, $email, $name);

        $assetIds = AttachmentHelper::saveUploads(UploadedFile::getInstancesByName('attachments'), $thread);

        Plugin::getInstance()->messages->post($thread, [
            'body' => $body,
            'authorEmail' => $email,
            'authorName' => $name,
            'attachmentAssetIds' => $assetIds,
        ]);

        // Email the guest their private access link and send them to it now.
        $participant = ParticipantRecord::findOne(['threadId' => $thread->id, 'email' => mb_strtolower($email), 'userId' => null]);
        $token = $participant ? Plugin::getInstance()->participants->mintToken($participant) : null;
        if ($token) {
            $this->_sendGuestLink($thread, $email, $name, $token, $participant);
            return $this->redirect("pigeon/t/{$token}");
        }

        Craft::$app->getSession()->setNotice(Craft::t('pigeon', 'Thanks! We’ve received your message.'));
        return $this->_back();
    }

    /**
     * Re-email an access link to a guest who lost theirs.
     */
    public function actionRequestLink(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        if ($this->_isHoneypotTripped()) {
            return $this->_back();
        }

        $email = trim((string)$request->getBodyParam('email'));

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && $this->_allowMail($email)) {
            $guests = Plugin::getInstance()->participants->getActiveGuestsByEmail($email);
            foreach ($guests as $participant) {
                $thread = Plugin::getInstance()->threads->getById($participant->threadId);
                if ($thread && $thread->threadStatus !== ThreadStatus::Closed->value) {
                    $token = Plugin::getInstance()->participants->mintToken($participant);
                    $this->_sendGuestLink($thread, $participant->email, $participant->name, $token, $participant);
                }
            }
        }

        // Always report success to avoid leaking which emails exist.
        Craft::$app->getSession()->setNotice(Craft::t('pigeon', 'If we found a matching conversation, a new link is on its way.'));
        return $this->_back();
    }

    private function _isHoneypotTripped(): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->enableHoneypot) {
            return false;
        }
        return trim((string)Craft::$app->getRequest()->getBodyParam($settings->honeypotField)) !== '';
    }

    /**
     * Whether this request may send a guest-link email to `$email`.
     *
     * Every guest thread and every link request sends an email to an address the visitor typed,
     * so this is three budgets, not one: per client, per recipient, and site-wide. Until 5.0.4 the
     * only budget was keyed on address *and* email together, so a client that changed the email
     * on every request was never throttled — and the site mailed whoever it was told to.
     */
    private function _allowMail(string $email): bool
    {
        return RateLimit::allowWindow('guest-mail', ...$this->_budget())
            && RateLimit::allowForWindow('guest-mail-to', mb_strtolower($email), ...$this->_budget());
    }

    /**
     * @return array{int, int} the configured budget: requests, and the window in seconds
     */
    private function _budget(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        return [$settings->rateLimitMaxMessages, $settings->rateLimitWindowSeconds];
    }

    /**
     * Back to where the form was, on this site.
     *
     * A hashed `redirect` input wins. Otherwise the page the form posted to, when it posted to
     * itself; the site's home page when it posted straight to an action URL. Never the Referer:
     * that is whatever site sent the browser here.
     */
    private function _back(): Response
    {
        $request = Craft::$app->getRequest();

        if ($request->getValidatedBodyParam('redirect') !== null) {
            return $this->redirectToPostedUrl();
        }

        $trigger = Craft::$app->getConfig()->getGeneral()->actionTrigger;

        if (str_starts_with($request->getPathInfo(), $trigger . '/')) {
            return $this->redirect(\craft\helpers\UrlHelper::siteUrl());
        }

        return $this->redirect($request->getUrl());
    }

    private function _sendGuestLink(Thread $thread, ?string $email, ?string $name, string $token, ?ParticipantRecord $participant = null): void
    {
        if (!$email) {
            return;
        }

        $settings = Plugin::getInstance()->getSettings();
        $link = \craft\helpers\UrlHelper::siteUrl("pigeon/t/{$token}");
        $view = Craft::$app->getView();
        $vars = ['thread' => $thread, 'link' => $link];

        $html = $view->renderTemplate('pigeon/_emails/guest-link', $vars, \craft\web\View::TEMPLATE_MODE_CP);
        $text = $view->renderTemplate('pigeon/_emails/guest-link.text', $vars, \craft\web\View::TEMPLATE_MODE_CP);

        $message = Craft::$app->getMailer()->compose()
            ->setTo($name ? [$email => $name] : $email)
            // Not the thread's subject: a visitor types that, and anything a visitor types into an
            // email the site sends to an address they chose is a phishing line in the site's name.
            ->setSubject(Craft::t('pigeon', 'Your conversation with {site}', ['site' => Craft::$app->getSystemName()]))
            ->setHtmlBody($html)
            ->setTextBody($text);

        if ($settings->fromEmail) {
            $message->setFrom($settings->fromName ? [$settings->fromEmail => $settings->fromName] : $settings->fromEmail);
        }

        // Reply by email (when on): answering this email posts to the conversation. Sent because
        // the guest did something, so it says so — `Auto-Submitted: auto-replied`.
        if ($participant !== null) {
            Plugin::getInstance()->inbound->prepareOutgoing($message, $thread, $participant, true);
        }

        $message->send();
    }
}
