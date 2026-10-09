<?php
/**
 * Signed inbound-email fixtures, one builder per provider.
 *
 * No real provider is reachable from the harness, so these build exactly what each provider posts
 * — Postmark's JSON with basic auth, Mailgun's form with its HMAC, SendGrid's multipart body with
 * an ECDSA signature over `timestamp . body` — signed with test secrets. The providers verify them
 * through the same code a real delivery takes; every "wrong" case is one of these with a single
 * thing changed.
 *
 * Plain functions so `inbound.php` and `inbound-http.php` share them.
 */

use justinholtweb\pigeon\mail\InboundRequest;

const FIXTURE_POSTMARK_USER = 'pigeon-inbound';
const FIXTURE_POSTMARK_PASSWORD = 'p0stmark-Test-Secret';
const FIXTURE_MAILGUN_KEY = 'key-mailgun-test-signing-key-0123456789';
const FIXTURE_DESK = 'messages@pigeon.example.com';

/**
 * @param array<string, mixed> $overrides Postmark JSON fields to set.
 * @param array<string, string> $headers Extra email headers.
 */
function postmarkPayload(array $overrides = [], array $headers = []): string
{
    $messageId = $overrides['_messageId'] ?? ('pm-' . bin2hex(random_bytes(6)) . '@example.org');
    unset($overrides['_messageId']);

    $allHeaders = [['Name' => 'Message-ID', 'Value' => '<' . $messageId . '>']];

    foreach ($headers as $name => $value) {
        $allHeaders[] = ['Name' => $name, 'Value' => $value];
    }

    return json_encode(array_merge([
        'FromName' => 'Pat Customer',
        'From' => 'pat@example.org',
        'FromFull' => ['Email' => 'pat@example.org', 'Name' => 'Pat Customer', 'MailboxHash' => ''],
        'To' => FIXTURE_DESK,
        'ToFull' => [['Email' => FIXTURE_DESK, 'Name' => 'Support', 'MailboxHash' => '']],
        'Cc' => '',
        'CcFull' => [],
        'OriginalRecipient' => FIXTURE_DESK,
        'Subject' => 'Re: Help with my order',
        'MessageID' => '73e6d360-66eb-11e1-8e72-a8904824019b',
        'ReplyTo' => '',
        'MailboxHash' => '',
        'Date' => 'Fri, 9 Oct 2026 09:00:00 +0000',
        'TextBody' => "My order has not arrived.\n\nPat",
        'HtmlBody' => '<p>My order has not arrived.</p>',
        'StrippedTextReply' => '',
        'Tag' => '',
        'Headers' => $allHeaders,
        'Attachments' => [],
    ], $overrides), JSON_UNESCAPED_SLASHES);
}

function postmarkRequest(string $json, ?string $user = FIXTURE_POSTMARK_USER, ?string $password = FIXTURE_POSTMARK_PASSWORD, string $tempDir = ''): InboundRequest
{
    return new InboundRequest(
        ['content-type' => 'application/json'],
        $json,
        [],
        [],
        $user,
        $password,
        $tempDir,
    );
}

/**
 * A Mailgun forward() post, signed.
 *
 * @param array<string, string> $fields Form fields to set or override.
 * @param array<string, array{name: string, type: string, tmp_name: string, size: int, error: int}> $files
 */
function mailgunRequest(array $fields = [], array $files = [], ?int $timestamp = null, ?string $token = null, string $key = FIXTURE_MAILGUN_KEY, string $tempDir = ''): InboundRequest
{
    $timestamp ??= time();
    $token ??= bin2hex(random_bytes(25));
    $messageId = $fields['_messageId'] ?? ('mg-' . bin2hex(random_bytes(6)) . '@example.org');
    unset($fields['_messageId']);

    $headers = [['Message-Id', '<' . $messageId . '>'], ['From', $fields['from'] ?? 'Pat Customer <pat@example.org>']];

    foreach (['In-Reply-To', 'References', 'Auto-Submitted', 'Precedence'] as $name) {
        if (isset($fields[$name])) {
            $headers[] = [$name, $fields[$name]];
            unset($fields[$name]);
        }
    }

    $post = array_merge([
        'recipient' => FIXTURE_DESK,
        'sender' => 'pat@example.org',
        'from' => 'Pat Customer <pat@example.org>',
        'subject' => 'Re: your ticket',
        'body-plain' => 'A reply.',
        'body-html' => '',
        'message-headers' => json_encode($headers),
        'attachment-count' => (string)count($files),
        'timestamp' => (string)$timestamp,
        'token' => $token,
        'signature' => hash_hmac('sha256', $timestamp . $token, $key),
    ], $fields);

    return new InboundRequest(['content-type' => 'multipart/form-data; boundary=x'], '', $post, $files, null, null, $tempDir);
}

/** A P-256 key pair for SendGrid's signed Parse webhook. @return array{0: OpenSSLAsymmetricKey, 1: string} private key, public PEM */
function sendgridKeys(): array
{
    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $public = openssl_pkey_get_details($private)['key'];

    return [$private, $public];
}

/** SendGrid's public key as it shows it: base64 DER, no PEM armour. */
function sendgridBareKey(string $pem): string
{
    return preg_replace('/-----[^-]+-----|\s+/', '', $pem);
}

/**
 * A raw SendGrid Parse multipart body, as PHP keeps it with enable_post_data_reading off.
 *
 * @param array<string, string> $fields
 * @param array<string, array{filename: string, type: string, bytes: string}> $files
 * @return array{0: string, 1: string} body, content type
 */
function sendgridBody(array $fields, array $files = []): array
{
    $boundary = 'xYzZY' . bin2hex(random_bytes(6));
    $body = '';

    foreach ($fields as $name => $value) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
    }

    foreach ($files as $name => $file) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"{$file['filename']}\"\r\nContent-Type: {$file['type']}\r\n\r\n{$file['bytes']}\r\n";
    }

    $body .= "--$boundary--\r\n";

    return [$body, "multipart/form-data; boundary=$boundary"];
}

/** @param OpenSSLAsymmetricKey $privateKey */
function sendgridRequest(string $body, string $contentType, $privateKey, ?int $timestamp = null, string $tempDir = ''): InboundRequest
{
    $timestamp ??= time();
    openssl_sign($timestamp . $body, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    return new InboundRequest([
        'content-type' => $contentType,
        'x-twilio-email-event-webhook-signature' => base64_encode($signature),
        'x-twilio-email-event-webhook-timestamp' => (string)$timestamp,
    ], $body, [], [], null, null, $tempDir);
}

/** A real 1×1 PNG. */
function tinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
}
