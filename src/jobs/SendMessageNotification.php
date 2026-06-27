<?php

namespace justinholtweb\pigeon\jobs;

use Craft;
use craft\helpers\UrlHelper;
use craft\queue\BaseJob;
use craft\web\View;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\records\MessageRecord;
use justinholtweb\pigeon\records\ParticipantRecord;

class SendMessageNotification extends BaseJob
{
    public int $messageId;
    public ?int $participantId = null;
    public ?string $adhocEmail = null;
    public bool $staffAlert = false;

    public function execute($queue): void
    {
        $message = MessageRecord::findOne($this->messageId);
        if (!$message) {
            return;
        }

        /** @var Thread|null $thread */
        $thread = Thread::find()->id($message->threadId)->status(null)->one();
        if (!$thread) {
            return;
        }

        [$toEmail, $toName, $link, $isStaff] = $this->resolveRecipient($thread);
        if (!$toEmail) {
            return;
        }

        $settings = Plugin::getInstance()->getSettings();
        $author = $message->authorName
            ?: ($message->authorUserId ? (string)Craft::$app->getUsers()->getUserById($message->authorUserId) : null)
            ?: ($message->authorEmail ?: Craft::t('pigeon', 'Someone'));

        $subject = $this->staffAlert
            ? Craft::t('pigeon', 'New support message: {subject}', ['subject' => $thread->title])
            : Craft::t('pigeon', 'New message: {subject}', ['subject' => $thread->title]);

        $view = Craft::$app->getView();
        $vars = [
            'thread' => $thread,
            'message' => $message,
            'author' => $author,
            'recipientName' => $toName,
            'link' => $link,
            'isStaff' => $isStaff,
        ];

        $html = $view->renderTemplate('pigeon/_emails/new-message', $vars, View::TEMPLATE_MODE_CP);
        $text = $view->renderTemplate('pigeon/_emails/new-message.text', $vars, View::TEMPLATE_MODE_CP);

        $mailer = Craft::$app->getMailer();
        $composed = $mailer->compose()
            ->setTo($toName ? [$toEmail => $toName] : $toEmail)
            ->setSubject($subject)
            ->setHtmlBody($html)
            ->setTextBody($text);

        if ($settings->fromEmail) {
            $composed->setFrom($settings->fromName
                ? [$settings->fromEmail => $settings->fromName]
                : $settings->fromEmail);
        }

        $composed->send();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('pigeon', 'Sending Pigeon message notification');
    }

    /**
     * @return array{0:?string,1:?string,2:?string,3:bool} [email, name, link, isStaff]
     */
    private function resolveRecipient(Thread $thread): array
    {
        // Ad-hoc support inbox alert → email goes to staff, link into the control panel.
        if ($this->adhocEmail) {
            return [
                $this->adhocEmail,
                null,
                UrlHelper::cpUrl("pigeon/threads/{$thread->id}"),
                true,
            ];
        }

        $participant = $this->participantId ? ParticipantRecord::findOne($this->participantId) : null;
        if (!$participant) {
            return [null, null, null, false];
        }

        // Logged-in user participant → front-end thread page (CP for staff).
        if ($participant->userId) {
            $user = Craft::$app->getUsers()->getUserById($participant->userId);
            $isStaff = $user !== null && ($user->admin || $user->can('pigeon:manageThreads'));
            $link = $isStaff
                ? UrlHelper::cpUrl("pigeon/threads/{$thread->id}")
                : UrlHelper::siteUrl("pigeon/threads/{$thread->id}");

            return [
                $participant->email ?: $user?->email,
                $participant->name ?: ($user ? (string)$user : null),
                $link,
                $isStaff,
            ];
        }

        // Guest participant → mint a fresh signed token link.
        $token = Plugin::getInstance()->participants->mintToken($participant);

        return [
            $participant->email,
            $participant->name,
            UrlHelper::siteUrl("pigeon/t/{$token}"),
            false,
        ];
    }
}
