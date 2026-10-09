<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail;

/**
 * A file that came with an email, sitting on disk.
 *
 * On disk rather than in memory because the webhook has to answer quickly and the work happens
 * later in a queue job — and the provider's upload is gone the moment the request ends. The
 * inbound service copies it somewhere durable, points `path` at the copy, and deletes the copy
 * once the job has stored it as an asset (or refused it).
 */
final class InboundAttachment
{
    public function __construct(
        public string $filename = '',
        public string $contentType = 'application/octet-stream',
        public int $size = 0,
        public string $path = '',
        public ?string $contentId = null,
    ) {
    }

    public function extension(): string
    {
        return strtolower((string)pathinfo($this->filename, PATHINFO_EXTENSION));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['filename'] ?? ''),
            (string)($data['contentType'] ?? 'application/octet-stream'),
            (int)($data['size'] ?? 0),
            (string)($data['path'] ?? ''),
            isset($data['contentId']) ? (string)$data['contentId'] : null,
        );
    }
}
