<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail;

/**
 * Parses a `multipart/form-data` body by hand.
 *
 * Only needed for one case, and it is worth knowing why. SendGrid signs the *raw* request body,
 * and PHP throws the raw body of a multipart request away: it parses it into `$_POST` and
 * `$_FILES` and leaves `php://input` empty. A site that wants SendGrid's signature checked turns
 * PHP's own parsing off for the webhook (`enable_post_data_reading = Off`), the raw body survives,
 * and this does the parsing PHP would have done — after the signature over exactly those bytes has
 * been verified.
 *
 * Binary-safe: file parts are written out byte for byte, never line-ending normalised.
 */
final class MultipartParser
{
    /** More parts than any real email has attachments. */
    private const MAX_PARTS = 200;

    /**
     * @param callable(string): string $store Writes file bytes somewhere and returns the path.
     * @return array{0: array<string, string>, 1: array<string, array{name: string, type: string, tmp_name: string, size: int, error: int}>}
     */
    public static function parse(string $body, string $contentType, callable $store): array
    {
        [, $params] = MimeParser::parameters($contentType);
        $boundary = $params['boundary'] ?? '';
        $fields = [];
        $files = [];

        if ($boundary === '' || $body === '') {
            return [$fields, $files];
        }

        $delimiter = '--' . $boundary;
        $segments = explode($delimiter, $body);
        array_shift($segments);
        $count = 0;

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '--') || ++$count > self::MAX_PARTS) {
                break;
            }

            // Each part starts after the delimiter's own line break and ends before the line break
            // that precedes the next delimiter.
            $segment = (string)preg_replace('/^[ \t]*\r?\n/', '', $segment, 1);

            if (str_ends_with($segment, "\r\n")) {
                $segment = substr($segment, 0, -2);
            } elseif (str_ends_with($segment, "\n")) {
                $segment = substr($segment, 0, -1);
            }

            $split = strpos($segment, "\r\n\r\n");
            $gap = 4;

            if ($split === false) {
                $split = strpos($segment, "\n\n");
                $gap = 2;
            }

            if ($split === false) {
                continue;
            }

            $headers = [];

            foreach (MimeParser::headers(substr($segment, 0, $split)) as [$name, $value]) {
                $headers[$name] = $value;
            }

            $content = substr($segment, $split + $gap);
            [, $disposition] = MimeParser::parameters($headers['content-disposition'] ?? '');
            $name = $disposition['name'] ?? null;

            if ($name === null || $name === '') {
                continue;
            }

            if (array_key_exists('filename', $disposition)) {
                $files[$name] = [
                    'name' => (string)$disposition['filename'],
                    'type' => strtolower(trim(explode(';', $headers['content-type'] ?? 'application/octet-stream')[0])),
                    'tmp_name' => $store($content),
                    'size' => strlen($content),
                    'error' => UPLOAD_ERR_OK,
                ];
                continue;
            }

            $fields[$name] = $content;
        }

        return [$fields, $files];
    }
}
