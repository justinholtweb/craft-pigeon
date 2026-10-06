<?php

namespace justinholtweb\pigeontests\unit\helpers;

use craft\web\UploadedFile;
use justinholtweb\pigeon\helpers\AttachmentHelper;
use justinholtweb\pigeontests\unit\PigeonTestCase;

class AttachmentHelperTest extends PigeonTestCase
{
    public function testNoFilesReturnsNothing(): void
    {
        self::assertSame([], AttachmentHelper::saveUploads([], $this->createGuestThread()));
    }

    public function testUploadsAreIgnoredWhenNoVolumeIsConfigured(): void
    {
        $settings = $this->plugin()->getSettings();
        $original = $settings->attachmentVolumeUid;
        $settings->attachmentVolumeUid = '';

        try {
            $file = new UploadedFile([
                'name' => 'note.txt',
                'tempName' => tempnam(sys_get_temp_dir(), 'pigeon'),
                'type' => 'text/plain',
                'size' => 10,
                'error' => UPLOAD_ERR_OK,
            ]);

            self::assertSame([], AttachmentHelper::saveUploads([$file], $this->createGuestThread()));
        } finally {
            $settings->attachmentVolumeUid = $original;
        }
    }

    public function testUnknownVolumeUidIsHandledGracefully(): void
    {
        $settings = $this->plugin()->getSettings();
        $original = $settings->attachmentVolumeUid;
        $settings->attachmentVolumeUid = 'not-a-real-volume-uid';

        try {
            $file = new UploadedFile([
                'name' => 'note.txt',
                'tempName' => tempnam(sys_get_temp_dir(), 'pigeon'),
                'type' => 'text/plain',
                'size' => 10,
                'error' => UPLOAD_ERR_OK,
            ]);

            self::assertSame([], AttachmentHelper::saveUploads([$file], $this->createGuestThread()));
        } finally {
            $settings->attachmentVolumeUid = $original;
        }
    }
}
