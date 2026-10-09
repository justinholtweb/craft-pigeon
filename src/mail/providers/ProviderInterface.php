<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail\providers;

use justinholtweb\pigeon\mail\InboundMessage;
use justinholtweb\pigeon\mail\InboundRequest;

/**
 * One inbound-mail provider: how to tell its deliveries are real, and how to read them.
 *
 * Verification and parsing are separate on purpose. `verify()` runs first, against the raw request,
 * and nothing is parsed — no JSON decoded, no file written — until it has said yes.
 */
interface ProviderInterface
{
    /** `postmark`, `mailgun`, `sendgrid`. The last URL segment of the webhook. */
    public static function handle(): string;

    /** Whether the secret this provider is verified with has been set. Unconfigured means refused. */
    public function isConfigured(): bool;

    /**
     * Null when the request is authentic; otherwise a short reason for the log. Never the expected
     * value, and never which half of a credential was wrong.
     */
    public function verify(InboundRequest $request, int $now): ?string;

    public function parse(InboundRequest $request): InboundMessage;
}
