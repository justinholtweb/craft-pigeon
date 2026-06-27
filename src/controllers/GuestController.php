<?php

namespace justinholtweb\pigeon\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\helpers\AttachmentHelper;
use justinholtweb\pigeon\helpers\RateLimiter;
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
            return $this->redirect($request->getReferrer() ?: 'pigeon');
        }

        $token = (string)$request->getRequiredBodyParam('token');
        $participant = Plugin::getInstance()->participants->findByToken($token);
        if (!$participant) {
            throw new BadRequestHttpException('Invalid or expired link.');
        }

        if (!$this->_passesRateLimit($participant->email ?: 'guest')) {
            Craft::$app->getSession()->setError(Craft::t('pigeon', 'You are sending messages too quickly. Please wait a moment.'));
            return $this->redirect("pigeon/t/{$token}");
        }

        $thread = Plugin::getInstance()->threads->getById($participant->threadId);
        if (!$thread) {
            throw new BadRequestHttpException('Thread not found.');
        }

        $body = trim((string)$request->getBodyParam('body'));
        $assetIds = AttachmentHelper::saveUploads(UploadedFile::getInstancesByName('attachments'));

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
            return $this->redirect($request->getReferrer() ?: '/');
        }

        $email = trim((string)$request->getBodyParam('email'));
        $name = trim((string)$request->getBodyParam('name')) ?: null;
        $subject = trim((string)$request->getBodyParam('subject'));
        $body = trim((string)$request->getBodyParam('body'));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Craft::$app->getSession()->setError(Craft::t('pigeon', 'A valid email address is required.'));
            return $this->redirect($request->getReferrer() ?: '/');
        }

        if ($body === '') {
            Craft::$app->getSession()->setError(Craft::t('pigeon', 'Your message cannot be empty.'));
            return $this->redirect($request->getReferrer() ?: '/');
        }

        if (!$this->_passesRateLimit($email)) {
            Craft::$app->getSession()->setError(Craft::t('pigeon', 'You are sending messages too quickly. Please wait a moment.'));
            return $this->redirect($request->getReferrer() ?: '/');
        }

        $threadsService = Plugin::getInstance()->threads;
        $thread = $threadsService->createSupportThread($subject, $email, $name);

        $assetIds = AttachmentHelper::saveUploads(UploadedFile::getInstancesByName('attachments'));

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
            $this->_sendGuestLink($thread, $email, $name, $token);
            return $this->redirect("pigeon/t/{$token}");
        }

        Craft::$app->getSession()->setNotice(Craft::t('pigeon', 'Thanks! We’ve received your message.'));
        return $this->redirect($request->getReferrer() ?: '/');
    }

    /**
     * Re-email an access link to a guest who lost theirs.
     */
    public function actionRequestLink(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        if ($this->_isHoneypotTripped()) {
            return $this->redirect($request->getReferrer() ?: '/');
        }

        $email = trim((string)$request->getBodyParam('email'));

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && $this->_passesRateLimit($email)) {
            $guests = Plugin::getInstance()->participants->getActiveGuestsByEmail($email);
            foreach ($guests as $participant) {
                $thread = Plugin::getInstance()->threads->getById($participant->threadId);
                if ($thread && $thread->threadStatus !== ThreadStatus::Closed->value) {
                    $token = Plugin::getInstance()->participants->mintToken($participant);
                    $this->_sendGuestLink($thread, $participant->email, $participant->name, $token);
                }
            }
        }

        // Always report success to avoid leaking which emails exist.
        Craft::$app->getSession()->setNotice(Craft::t('pigeon', 'If we found a matching conversation, a new link is on its way.'));
        return $this->redirect($request->getReferrer() ?: '/');
    }

    private function _isHoneypotTripped(): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->enableHoneypot) {
            return false;
        }
        return trim((string)Craft::$app->getRequest()->getBodyParam($settings->honeypotField)) !== '';
    }

    private function _passesRateLimit(string $identifier): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $ip = Craft::$app->getRequest()->getUserIP() ?? 'unknown';
        return RateLimiter::hit(
            "guest:{$ip}:{$identifier}",
            $settings->rateLimitMaxMessages,
            $settings->rateLimitWindowSeconds,
        );
    }

    private function _sendGuestLink(Thread $thread, ?string $email, ?string $name, string $token): void
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
            ->setSubject(Craft::t('pigeon', 'Your conversation: {subject}', ['subject' => $thread->title]))
            ->setHtmlBody($html)
            ->setTextBody($text);

        if ($settings->fromEmail) {
            $message->setFrom($settings->fromName ? [$settings->fromEmail => $settings->fromName] : $settings->fromEmail);
        }

        $message->send();
    }
}
