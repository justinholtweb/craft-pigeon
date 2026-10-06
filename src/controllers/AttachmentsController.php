<?php

namespace justinholtweb\pigeon\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\Plugin;
use justinholtweb\pigeon\records\AttachmentRecord;
use justinholtweb\pigeon\records\MessageRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves message attachments to whoever may read the message — and nobody else.
 *
 * Until 5.0.4 attachments were linked by their asset URL, so on a volume with public URLs a guest's
 * upload was a public file: anyone with the link (or a good guess at it) could fetch it without the
 * thread's token. Every link Pigeon renders now points here instead.
 */
class AttachmentsController extends Controller
{
    protected array|bool|int $allowAnonymous = ['download'];

    /**
     * @param int $id the attachment
     * @param string|null $access a guest's access token, from their link. Not `token`: Craft
     * claims that query parameter for its own tokens and answers 400 when it isn't one.
     */
    public function actionDownload(int $id, ?string $access = null): Response
    {
        $token = $access;

        $attachment = AttachmentRecord::findOne($id);
        $message = $attachment ? MessageRecord::findOne($attachment->messageId) : null;
        $thread = $message ? Plugin::getInstance()->threads->getById((int)$message->threadId) : null;

        // Not Forbidden: whether an attachment exists is itself not something to tell a stranger.
        if (!$attachment || !$message || !$thread || !$this->_canRead($thread, $message, $token)) {
            throw new NotFoundHttpException('Attachment not found.');
        }

        $asset = $attachment->assetId ? Asset::find()->id($attachment->assetId)->status(null)->one() : null;

        if (!$asset) {
            throw new NotFoundHttpException('Attachment not found.');
        }

        // Always as a download: an uploaded .html or .svg opened inline would run on the site's origin.
        return $this->response->sendStreamAsFile($asset->getStream(), $asset->getFilename(), [
            'mimeType' => $asset->getMimeType(),
            'inline' => false,
        ]);
    }

    private function _canRead(Thread $thread, MessageRecord $message, ?string $token): bool
    {
        // A guest, by the token from their link. Guests never see internal notes.
        if ($token !== null && $token !== '') {
            $participant = Plugin::getInstance()->participants->findByToken($token);

            return $participant !== null
                && (int)$participant->threadId === (int)$thread->id
                && !$message->isInternalNote;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if (!$user) {
            return false;
        }

        // Staff, in the control panel inbox — notes included.
        if (Plugin::canViewInCp($user, $thread)) {
            return true;
        }

        // A participant, on the front end.
        return !$message->isInternalNote
            && Thread::find()->id($thread->id)->forUser($user->id)->status(null)->exists();
    }
}
