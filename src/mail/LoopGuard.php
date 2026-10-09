<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail;

/**
 * Mail no person wrote, which must never become a message in a conversation.
 *
 * The failure this exists for is the oldest one in help desks: a customer's out-of-office answers
 * the desk's "we've got your message", which raises a ticket, which sends "we've got your message",
 * which the out-of-office answers… until somebody notices the ticket count. RFC 3834 says how
 * automated mail should label itself; most of it does, and what doesn't is caught by the other
 * signals here.
 */
final class LoopGuard
{
    public const AUTO_SUBMITTED = 'auto-submitted';
    public const PRECEDENCE = 'precedence';
    public const AUTO_REPLY = 'auto-reply';
    public const MAILING_LIST = 'mailing-list';
    public const BOUNCE = 'bounce';
    public const OWN_ADDRESS = 'own-address';

    /**
     * Why this message must be ignored, or null when a person plausibly wrote it.
     *
     * @param list<string> $ownAddresses The desk's inbound address and anything else it sends as.
     */
    public static function reason(InboundMessage $message, array $ownAddresses): ?string
    {
        // RFC 3834: anything other than "no" means a machine sent it.
        $autoSubmitted = strtolower(trim((string)$message->header('auto-submitted')));

        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return self::AUTO_SUBMITTED;
        }

        $precedence = strtolower(trim((string)$message->header('precedence')));

        if (in_array($precedence, ['bulk', 'list', 'junk', 'auto_reply'], true)) {
            return self::PRECEDENCE;
        }

        // The non-standard ones: Exchange, older autoresponders and a long tail of ticketing
        // systems that never read RFC 3834.
        foreach (['x-autoreply', 'x-autorespond', 'x-autoresponder', 'x-auto-reply'] as $name) {
            if ($message->header($name) !== null) {
                return self::AUTO_REPLY;
            }
        }

        if (strtolower(trim((string)$message->header('x-auto-response-suppress'))) === 'all'
            && preg_match('/^(automatic reply|auto(matic)?[- ]?reply|out of (the )?office)\b/i', $message->subject) === 1
        ) {
            return self::AUTO_REPLY;
        }

        if ($message->header('list-id') !== null || $message->header('list-unsubscribe') !== null) {
            return self::MAILING_LIST;
        }

        $local = strtolower(explode('@', $message->fromEmail)[0]);

        if (in_array($local, ['mailer-daemon', 'postmaster', 'mail-daemon'], true)
            || str_starts_with(strtolower(trim((string)$message->header('content-type'))), 'multipart/report')
        ) {
            return self::BOUNCE;
        }

        foreach ($ownAddresses as $own) {
            if ($own !== '' && Addresses::isOwn($message->fromEmail, $own)) {
                return self::OWN_ADDRESS;
            }
        }

        return null;
    }
}
