<?php

namespace justinholtweb\pigeon\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Front-end thread access for logged-in Craft users.
 */
class ThreadsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireLogin();
        return true;
    }

    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $this->renderTemplate('pigeon/_front/index', [
            'threads' => Plugin::getInstance()->threads->getThreadsForUser($user),
            'unreadCount' => Plugin::getInstance()->participants->unreadThreadCountForUser($user->id),
        ]);
    }

    public function actionView(int $threadId): Response
    {
        $thread = $this->_requireParticipantThread($threadId);

        $messages = Plugin::getInstance()->messages->getForThread($threadId, includeInternal: false);

        $user = Craft::$app->getUser()->getIdentity();
        $participant = Plugin::getInstance()->participants->getForUser($threadId, $user->id);
        if ($participant) {
            Plugin::getInstance()->participants->markRead($participant);
        }

        return $this->renderTemplate('pigeon/_front/thread', [
            'thread' => $thread,
            'messages' => $messages,
        ]);
    }

    public function actionStart(): ?Response
    {
        $this->requirePostRequest();

        $settings = Plugin::getInstance()->getSettings();
        $request = Craft::$app->getRequest();
        $user = Craft::$app->getUser()->getIdentity();

        $type = $request->getBodyParam('type', 'support');
        $subject = trim((string)$request->getBodyParam('subject'));
        $body = trim((string)$request->getBodyParam('body'));

        if ($body === '') {
            Craft::$app->getSession()->setError(Craft::t('pigeon', 'Your message cannot be empty.'));
            return $this->redirect($request->getReferrer() ?: 'pigeon/threads');
        }

        $threadsService = Plugin::getInstance()->threads;

        if ($type === 'direct') {
            if (!$settings->allowUserThreads) {
                throw new ForbiddenHttpException('User-to-user threads are disabled.');
            }
            $recipientIds = $this->_resolveRecipients($request->getBodyParam('recipients', ''));
            if (!$recipientIds) {
                Craft::$app->getSession()->setError(Craft::t('pigeon', 'Please choose at least one valid recipient.'));
                return $this->redirect($request->getReferrer() ?: 'pigeon/threads');
            }
            $thread = $threadsService->createDirectThread($subject, $user->id, $recipientIds);
        } else {
            $thread = $threadsService->createSupportThread($subject, $user->email, (string)$user, $user->id);
        }

        Plugin::getInstance()->messages->post($thread, [
            'body' => $body,
            'authorUserId' => $user->id,
            'authorName' => (string)$user,
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('pigeon', 'Message sent.'));
        return $this->redirect("pigeon/threads/{$thread->id}");
    }

    public function actionMarkRead(): ?Response
    {
        $this->requirePostRequest();

        $threadId = (int)Craft::$app->getRequest()->getRequiredBodyParam('threadId');
        $this->_requireParticipantThread($threadId);

        $user = Craft::$app->getUser()->getIdentity();
        $participant = Plugin::getInstance()->participants->getForUser($threadId, $user->id);
        if ($participant) {
            Plugin::getInstance()->participants->markRead($participant);
        }

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }
        return $this->redirectToPostedUrl();
    }

    private function _requireParticipantThread(int $threadId): Thread
    {
        $user = Craft::$app->getUser()->getIdentity();
        $thread = Thread::find()->id($threadId)->forUser($user->id)->status(null)->one();
        if (!$thread) {
            throw new NotFoundHttpException('Thread not found.');
        }
        return $thread;
    }

    /**
     * Resolve a comma/space-separated list of usernames, emails, or IDs to user IDs.
     *
     * @return int[]
     */
    private function _resolveRecipients(string $raw): array
    {
        $tokens = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ids = [];

        foreach ($tokens as $token) {
            $user = is_numeric($token)
                ? Craft::$app->getUsers()->getUserById((int)$token)
                : (str_contains($token, '@')
                    ? Craft::$app->getUsers()->getUserByUsernameOrEmail($token)
                    : Craft::$app->getUsers()->getUserByUsernameOrEmail($token));

            if ($user) {
                $ids[] = (int)$user->id;
            }
        }

        return array_values(array_unique($ids));
    }
}
