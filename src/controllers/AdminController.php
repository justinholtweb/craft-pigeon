<?php

namespace justinholtweb\pigeon\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\helpers\AttachmentHelper;
use justinholtweb\pigeon\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Control-panel inbox: thread index, conversation view, replies, notes,
 * assignment, and status changes.
 */
class AdminController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Every CP inbox action requires at least read access.
        $this->requirePermission('pigeon:accessPlugin');

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('pigeon/_cp/index', [
            'elementType' => Thread::class,
        ]);
    }

    public function actionSettings(): Response
    {
        return $this->redirect(UrlHelper::cpUrl('settings/plugins/pigeon'));
    }

    public function actionThread(int $threadId): Response
    {
        $thread = Plugin::getInstance()->threads->getById($threadId);
        if (!$thread) {
            throw new NotFoundHttpException('Thread not found.');
        }

        $messages = Plugin::getInstance()->messages->getForThread($threadId, includeInternal: true);

        // Mark read for the viewing staff member.
        $user = Craft::$app->getUser()->getIdentity();
        $participant = Plugin::getInstance()->participants->ensureUser($threadId, $user->id, \justinholtweb\pigeon\enums\ParticipantRole::Admin->value);
        Plugin::getInstance()->participants->markRead($participant);

        $staffOptions = $this->_staffOptions();

        return $this->renderTemplate('pigeon/_cp/thread', [
            'thread' => $thread,
            'messages' => $messages,
            'participants' => Plugin::getInstance()->participants->getForThread($threadId),
            'staffOptions' => $staffOptions,
            'title' => $thread->title,
        ]);
    }

    public function actionReply(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('pigeon:manageThreads');

        $thread = $this->_getThreadFromRequest();
        $body = trim((string)Craft::$app->getRequest()->getBodyParam('body'));
        $isNote = (bool)Craft::$app->getRequest()->getBodyParam('isInternalNote');

        $assetIds = AttachmentHelper::saveUploads(UploadedFile::getInstancesByName('attachments'));

        if ($body === '' && !$assetIds) {
            Craft::$app->getSession()->setError(Craft::t('pigeon', 'Message cannot be empty.'));
            return $this->redirectToPostedUrl();
        }

        $user = Craft::$app->getUser()->getIdentity();

        Plugin::getInstance()->messages->post($thread, [
            'body' => $body,
            'authorUserId' => $user->id,
            'authorName' => (string)$user,
            'isInternalNote' => $isNote,
            'attachmentAssetIds' => $assetIds,
        ]);

        Craft::$app->getSession()->setNotice($isNote
            ? Craft::t('pigeon', 'Note added.')
            : Craft::t('pigeon', 'Reply sent.'));

        return $this->redirectToPostedUrl();
    }

    public function actionAssign(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('pigeon:assignThreads');

        $thread = $this->_getThreadFromRequest();
        $assigneeId = Craft::$app->getRequest()->getBodyParam('assigneeId');
        $assigneeId = $assigneeId !== '' && $assigneeId !== null ? (int)$assigneeId : null;

        Plugin::getInstance()->threads->assign($thread, $assigneeId);
        Craft::$app->getSession()->setNotice(Craft::t('pigeon', 'Thread assigned.'));

        return $this->redirectToPostedUrl();
    }

    public function actionStatus(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('pigeon:manageThreads');

        $thread = $this->_getThreadFromRequest();
        $status = ThreadStatus::tryFrom((string)Craft::$app->getRequest()->getBodyParam('status'));
        if (!$status) {
            throw new ForbiddenHttpException('Invalid status.');
        }

        Plugin::getInstance()->threads->setStatus($thread, $status);
        Craft::$app->getSession()->setNotice(Craft::t('pigeon', 'Status updated.'));

        return $this->redirectToPostedUrl();
    }

    private function _getThreadFromRequest(): Thread
    {
        $threadId = (int)Craft::$app->getRequest()->getRequiredBodyParam('threadId');
        $thread = Plugin::getInstance()->threads->getById($threadId);
        if (!$thread) {
            throw new NotFoundHttpException('Thread not found.');
        }
        return $thread;
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    private function _staffOptions(): array
    {
        $options = [['label' => Craft::t('pigeon', '— Unassigned —'), 'value' => '']];

        // Staff = admins plus anyone whose group grants thread management.
        $users = \craft\elements\User::find()
            ->status('active')
            ->limit(200)
            ->all();

        foreach ($users as $user) {
            if ($user->admin || $user->can('pigeon:manageThreads')) {
                $options[] = ['label' => (string)$user, 'value' => (string)$user->id];
            }
        }

        return $options;
    }
}
