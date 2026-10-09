<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail;

use RuntimeException;

/**
 * What a provider's webhook delivered, before anybody believes it.
 *
 * Built from the live request by the controller, or by hand from a fixture in the test suite —
 * which is the reason it exists: the providers verify and parse *this*, never `$_POST` or Craft's
 * request object, so a signed fixture exercises exactly the code a real delivery does.
 */
final class InboundRequest
{
    /**
     * @param array<string, string> $headers Lower-cased header name => value.
     * @param array<string, mixed> $post Parsed form fields, when PHP parsed them.
     * @param array<string, array{name: string, type: string, tmp_name: string, size: int, error?: int}> $files
     */
    public function __construct(
        public array $headers = [],
        public string $rawBody = '',
        public array $post = [],
        public array $files = [],
        public ?string $authUser = null,
        public ?string $authPassword = null,
        public string $tempDir = '',
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function field(string $name): ?string
    {
        $value = $this->post[$name] ?? null;

        return is_scalar($value) ? (string)$value : null;
    }

    /** The media type without parameters: `multipart/form-data`, `application/json`. */
    public function contentType(): string
    {
        return strtolower(trim(explode(';', (string)$this->header('content-type'))[0]));
    }

    /**
     * Write bytes to a new file in the temp directory and return its path.
     *
     * Every decoded attachment goes through here, so there is one place that names files —
     * never after anything the sender chose.
     */
    public function writeTemp(string $bytes): string
    {
        $dir = $this->tempDir !== '' ? $this->tempDir : sys_get_temp_dir();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create the inbound temp directory $dir.");
        }

        $path = $dir . DIRECTORY_SEPARATOR . 'in-' . bin2hex(random_bytes(12));

        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException("Cannot write an inbound attachment to $dir.");
        }

        return $path;
    }
}
