<?php

namespace Plugins\TicketMail;

/** An emailed answer without the earlier messages its mail app quotes under it, nor its signature. */
class QuotedText
{
    /** Lines where the quoted earlier message begins (Gmail, Apple Mail, Outlook, Thunderbird, in English and Greek). */
    private const CUT = [
        '/^On\s.{3,}\swrote:\s*$/i',
        '/^Στις\s.{3,}\sέγραψε:\s*$/iu',
        '/^Ο χρήστης\s.{3,}\sέγραψε:\s*$/iu',
        '/^-{2,}\s*(Original Message|Αρχικό μήνυμα|Forwarded message|Προωθημένο μήνυμα)\s*-{2,}/iu',
        '/^_{10,}\s*$/',
        '/^-- ?$/',
    ];

    public static function strip(string $text): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $kept = [];

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            // Gmail may wrap "On … <address>" and "wrote:" over two lines
            $joined = $trimmed.' '.trim($lines[$i + 1] ?? '');

            // Outlook's header block: "From: …" then "Sent: …" / "Date: …"
            $outlook = preg_match('/^(From|Από):\s/iu', $trimmed)
                && preg_match('/^(Sent|Date|Στάλθηκε|Ημερομηνία|To|Προς):\s/iu', trim($lines[$i + 1] ?? ''));

            if ($outlook || self::cuts($trimmed) || (! str_ends_with($trimmed, ':') && preg_match('/^(On|Στις)\s/iu', $trimmed) && self::cuts($joined))) {
                break;
            }

            $kept[] = $line;
        }

        // Quoted lines (">") at the end, and blank ones
        while ($kept !== [] && (trim(end($kept)) === '' || str_starts_with(ltrim(end($kept)), '>'))) {
            array_pop($kept);
        }

        return trim(implode("\n", $kept));
    }

    /** Plain text from an email that has only HTML, leaving out what it quotes. */
    public static function fromHtml(string $html): string
    {
        $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<blockquote\b[^>]*>.*?</blockquote>#is', '', $html) ?? $html;
        $html = preg_replace('#<div[^>]+class="?gmail_quote[^>]*>.*$#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|li|tr|h[1-6])>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    private static function cuts(string $line): bool
    {
        foreach (self::CUT as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }
}
