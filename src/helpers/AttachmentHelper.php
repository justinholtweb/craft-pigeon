<?php

namespace justinholtweb\pigeon\helpers;

use Craft;
use craft\elements\Asset;
use craft\web\UploadedFile;
use justinholtweb\pigeon\Plugin;

class AttachmentHelper
{
    /**
     * Validate and store uploaded files as Craft assets in the configured volume.
     *
     * @param UploadedFile[] $files
     * @return int[] Saved asset IDs.
     */
    public static function saveUploads(array $files): array
    {
        $files = array_filter($files);
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

        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
        if (!$folder) {
            return [];
        }

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
}
