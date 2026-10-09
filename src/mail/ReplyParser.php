<?php

declare(strict_types=1);

namespace justinholtweb\pigeon\mail;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * What the person actually wrote, without everything their mail client wrapped around it.
 *
 * A reply arrives carrying the entire conversation underneath it, a signature, and frequently
 * "Sent from my iPhone". Appending all of that to the ticket would make every timeline entry the
 * whole thread again, one level deeper each time. This cuts the new text out.
 *
 * Heuristics, so they are conservative on purpose: when in doubt, keep the text. An agent reading
 * one quoted line too many loses a second; an agent missing the sentence that explained the
 * problem loses the ticket.
 *
 * HTML is **never** passed through. It is turned into text here, and the text is what the rest of
 * the pipeline sees — so nothing a stranger's mail client produced is ever rendered as markup.
 */
final class ReplyParser
{
    /** Lines that introduce the quoted message in the common clients and languages. */
    private const QUOTE_HEADERS = [
        '/^On\s.{1,300}\swrote:\s*$/iu',
        '/^-{2,}\s*Original Message\s*-{2,}\s*$/iu',
        '/^-{2,}\s*Forwarded message\s*-{2,}\s*$/iu',
        '/^_{20,}\s*$/u',
        '/^Le\s.{1,300}\sa\s+écrit\s*:\s*$/iu',
        '/^Am\s.{1,300}\sschrieb\s.{0,300}:\s*$/iu',
        '/^El\s.{1,300}\sescribió\s*:\s*$/iu',
        '/^Op\s.{1,300}\sschreef\s.{0,300}:\s*$/iu',
        '/^Il\s.{1,300}\sha\s+scritto\s*:\s*$/iu',
    ];

    /** Lines a signature starts with. `-- ` is RFC 3676's own delimiter. */
    private const SIGNATURES = [
        '/^-- ?$/',
        '/^Sent from my \w+/i',
        '/^Sent from (Mail|Outlook|Yahoo Mail|Gmail)\b/i',
        '/^Get Outlook for /i',
        '/^Envoyé de mon /iu',
        '/^Von meinem .{1,40} gesendet/iu',
    ];

    /**
     * The new text of a reply.
     *
     * @param string $text The plain-text part, if the message had one.
     * @param string $html The HTML part, used when there is no text part.
     */
    public static function newText(string $text, string $html = ''): string
    {
        if (trim($text) === '' && trim($html) !== '') {
            $text = self::htmlToText($html);
        }

        return self::strip($text);
    }

    /** Cut quoted history and the signature from plain text. */
    public static function strip(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Non-breaking spaces from HTML clients make "On … wrote:" fail to match.
        $text = str_replace("\u{00A0}", ' ', $text);
        $lines = explode("\n", $text);
        $count = count($lines);
        $cut = $count;

        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);

            // "On Tue, 6 Oct 2026 at 09:14, Support <support+…@example.com>" + "wrote:" on the next
            // line: Gmail wraps the attribution when the address is long.
            $joined = $i + 1 < $count ? $line . ' ' . trim($lines[$i + 1]) : $line;

            if (self::isQuoteHeader($line) || (str_starts_with($line, 'On ') && self::isQuoteHeader($joined))) {
                $cut = $i;
                break;
            }

            if (self::isOutlookHeaderBlock($lines, $i)) {
                $cut = $i;
                break;
            }
        }

        $lines = array_slice($lines, 0, $cut);

        // A trailing block of `>` quotes with nothing new after it.
        $end = count($lines);

        while ($end > 0 && (trim($lines[$end - 1]) === '' || str_starts_with(ltrim($lines[$end - 1]), '>'))) {
            $end--;
        }

        $lines = array_slice($lines, 0, $end);

        foreach ($lines as $index => $line) {
            foreach (self::SIGNATURES as $pattern) {
                if (preg_match($pattern, rtrim($line)) === 1) {
                    $lines = array_slice($lines, 0, $index);
                    break 2;
                }
            }
        }

        $result = implode("\n", array_map('rtrim', $lines));
        $result = (string)preg_replace("/\n{3,}/", "\n\n", $result);

        return trim($result);
    }

    /**
     * Outlook's quoted header: `From:` followed within a few lines by `Sent:`/`Date:` and
     * `To:`/`Subject:`. A line that merely starts "From:" in the middle of a message is not one.
     *
     * @param list<string> $lines
     */
    private static function isOutlookHeaderBlock(array $lines, int $i): bool
    {
        if (preg_match('/^\*?From:\*?\s+\S/i', trim($lines[$i])) !== 1) {
            return false;
        }

        $window = array_map('trim', array_slice($lines, $i + 1, 5));
        $hasDate = false;
        $hasOther = false;

        foreach ($window as $line) {
            $hasDate = $hasDate || preg_match('/^\*?(Sent|Date):\*?\s/i', $line) === 1;
            $hasOther = $hasOther || preg_match('/^\*?(To|Subject):\*?\s/i', $line) === 1;
        }

        return $hasDate && $hasOther;
    }

    private static function isQuoteHeader(string $line): bool
    {
        foreach (self::QUOTE_HEADERS as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * HTML to readable plain text: blocks become lines, links keep their address, scripts and
     * styles and quoted history are removed rather than flattened into the text.
     */
    public static function htmlToText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The XML declaration tells libxml the bytes are UTF-8; without it they are read as
        // Latin-1 and every accented character comes out as two.
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $remove = $xpath->query(
            '//script | //style | //head | //title | //noscript | //template | //blockquote'
            . ' | //*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote ")]'
            . ' | //*[contains(concat(" ", normalize-space(@class), " "), " yahoo_quoted ")]'
            . ' | //*[contains(concat(" ", normalize-space(@class), " "), " moz-cite-prefix ")]'
            . ' | //*[@id="divRplyFwdMsg"] | //*[@id="appendonsend"]'
        );

        if ($remove !== false) {
            foreach (iterator_to_array($remove) as $node) {
                // Outlook puts the quoted message *after* its header div, as siblings.
                if ($node instanceof DOMElement && in_array($node->getAttribute('id'), ['divRplyFwdMsg', 'appendonsend'], true)) {
                    while ($node->nextSibling !== null) {
                        $node->parentNode?->removeChild($node->nextSibling);
                    }
                }

                $node->parentNode?->removeChild($node);
            }
        }

        $body = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        $text = $body !== null ? self::nodeText($body) : '';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string)preg_replace('/[ \t]+/', ' ', $text);
        $text = (string)preg_replace('/ *\n */', "\n", $text);
        $text = (string)preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private static function nodeText(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return (string)preg_replace('/\s+/', ' ', (string)$node->nodeValue);
        }

        if (!$node instanceof DOMElement && $node->nodeType !== XML_DOCUMENT_NODE) {
            return '';
        }

        $tag = $node instanceof DOMElement ? strtolower($node->tagName) : '';

        if ($tag === 'br') {
            return "\n";
        }

        $inner = '';

        foreach ($node->childNodes as $child) {
            $inner .= self::nodeText($child);
        }

        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            $label = trim($inner);

            if ($href !== '' && preg_match('#^(https?:|mailto:)#i', $href) === 1 && $label !== '' && $label !== $href && 'mailto:' . $label !== $href) {
                return $label . ' (' . $href . ')';
            }

            return $inner;
        }

        if ($tag === 'li') {
            return "\n- " . trim($inner);
        }

        if (in_array($tag, ['p', 'div', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'pre', 'section', 'article', 'header', 'footer'], true)) {
            return "\n" . $inner . "\n";
        }

        if ($tag === 'td' || $tag === 'th') {
            return $inner . ' ';
        }

        return $inner;
    }
}
