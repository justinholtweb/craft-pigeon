<?php

namespace justinholtweb\pigeon\helpers;

use Craft;
use craft\elements\Asset;
use craft\web\UploadedFile;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\Plugin;

class AttachmentHelper
{
    /** The folder, inside the attachment volume, that holds every thread's files. */
    public const FOLDER = 'pigeon';

    /**
     * Validate and store uploaded files as Craft assets in the configured volume.
     *
     * Each thread's files go in a folder of their own, named by the thread's UID — `pigeon/<uid>/`
     * — rather than the volume root, where a guest's upload sat next to everyone else's under a
     * guessable name. The files are served through `pigeon/attachments/download`, which checks the
     * reader's access; on a volume with public URLs they are *also* reachable directly, which the
     * settings screen warns about.
     *
     * @param UploadedFile[] $files
     * @return int[] Saved asset IDs.
     */
    public static function saveUploads(array $files, Thread $thread): array
    {
        if (!$files) {
            return [];
        }

        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->attachmentVolumeUid) {
            return [];
        }

        $volume = Craft::$app->getVolumes()->getVolumeByUid($settings->attachmentVolumeUid);
        if (!$volume) {
            return [];
        }

        $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(self::FOLDER . '/' . $thread->uid, $volume);

        $maxBytes = $settings->maxAttachmentSizeMb * 1024 * 1024;
        $allowed = array_map('strtolower', $settings->allowedAttachmentExtensions);

        $assetIds = [];

        foreach (array_slice($files, 0, $settings->maxAttachmentsPerMessage) as $file) {
            if ($file->getHasError()) {
                continue;
            }

            $ext = strtolower($file->getExtension());
            if ($allowed && !in_array($ext, $allowed, true)) {
                continue;
            }
            if ($maxBytes > 0 && $file->size > $maxBytes) {
                continue;
            }

            $asset = new Asset();
            $asset->tempFilePath = $file->tempName;
            $asset->setFilename($file->name);
            $asset->newFolderId = $folder->id;
            $asset->setVolumeId($volume->id);
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (Craft::$app->getElements()->saveElement($asset)) {
                $assetIds[] = (int)$asset->id;
            }
        }

        return $assetIds;
    }

    /**
     * Store one file that is already on disk — an attachment that arrived by email and has been
     * screened — as an asset in the thread's folder.
     *
     * The caller has checked the extension, size and count against the same settings
     * {@see saveUploads()} reads; this only checks the volume is still there.
     *
     * @return int|null The asset ID, or null when there is no volume or the save failed.
     */
    public static function saveFile(string $path, string $filename, Thread $thread): ?int
    {
        $settings = Plugin::getInstance()->getSettings();
        $volume = $settings->attachmentVolumeUid ? Craft::$app->getVolumes()->getVolumeByUid($settings->attachmentVolumeUid) : null;

        if (!$volume || !is_file($path)) {
            return null;
        }

        $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(self::FOLDER . '/' . $thread->uid, $volume);

        // Craft moves the temp file into the volume; hand it a copy so the caller's file is its own.
        $temp = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'pigeon-' . bin2hex(random_bytes(8));

        if (!@copy($path, $temp)) {
            return null;
        }

        $asset = new Asset();
        $asset->tempFilePath = $temp;
        $asset->setFilename(\craft\helpers\Assets::prepareAssetName($filename));
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($volume->id);
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            @unlink($temp);

            return null;
        }

        return (int)$asset->id;
    }
}
