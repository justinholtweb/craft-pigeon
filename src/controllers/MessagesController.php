<?php

namespace justinholtweb\pigeon\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\enums\ThreadStatus;
use justinholtweb\pigeon\helpers\AttachmentHelper;
use justinholtweb\pigeon\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Front-end message posting for logged-in Craft users.
 */
class MessagesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireLogin();
        return true;
    }

    public function actionReply(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $user = Craft::$app->getUser()->getIdentity();
        $threadId = (int)$request->getRequiredBodyParam('threadId');

        // The forUser() scope guarantees the user actually participates.
        $thread = Thread::find()->id($threadId)->forUser($user->id)->status(null)->one();
        if (!$thread) {
            throw new NotFoundHttpException('Thread not found.');
        }

        $body = trim((string)$request->getBodyParam('body'));
        $assetIds = AttachmentHelper::saveUploads(UploadedFile::getInstancesByName('attachments'));

        if ($body === '' && !$assetIds) {
            Craft::$app->getSession()->setError(Craft::t('pigeon', 'Your message cannot be empty.'));
            return $this->redirect("pigeon/threads/{$threadId}");
        }

        if ($thread->threadStatus === ThreadStatus::Closed->value) {
            Plugin::getInstance()->threads->setStatus($thread, ThreadStatus::Open);
        }

        Plugin::getInstance()->messages->post($thread, [
            'body' => $body,
            'authorUserId' => $user->id,
            'authorName' => (string)$user,
            'attachmentAssetIds' => $assetIds,
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('pigeon', 'Reply sent.'));
        return $this->redirect("pigeon/threads/{$threadId}");
    }
}
