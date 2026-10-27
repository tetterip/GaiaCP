<?php

namespace App\Support;

use App\Enums\MailTemplate;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateTranslation;
use App\Payments\PaymentMethods;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Subject, body and on/off switch of each email: the admin's edit from `email_templates`, else
 * the built-in one. Placeholders are written WHMCS-style, `{$client_first_name}`; unknown ones
 * are left as they are so a typo shows up in the preview.
 */
class MailTemplates
{
    /**
     * The text in a language (default: the current one): the admin's text in it, else its built-in text, else the
     * default language's.
     *
     * @return array{subject: string, body: string, enabled: bool} the text as stored, placeholders not filled in
     */
    public static function get(MailTemplate $template, ?string $locale = null): array
    {
        $stored = EmailTemplate::query()->find($template->value);
        $default = Locales::default();

        $text = [
            'subject' => $stored->subject ?? $template->defaultSubject($default),
            'body' => $stored->body ?? $template->defaultBody($default),
            'enabled' => $stored->enabled ?? $template->enabledByDefault(),
        ];

        $locale = Locales::normalize($locale ?? app()->getLocale());

        if (! Locales::isDefault($locale)) {
            $translation = self::translation($template, $locale);
            $fallback = self::fallback($template, $locale, $text);
            $text['subject'] = $translation->subject ?? $fallback['subject'];
            $text['body'] = $translation->body ?? $fallback['body'];
        }

        return $text;
    }

    /**
     * What another language shows until the admin writes its own text: its built-in text, else the default language's.
     *
     * @param  array{subject: string, body: string}|null  $default  the default language's text, when already read
     * @return array{subject: string, body: string}
     */
    public static function fallback(MailTemplate $template, string $locale, ?array $default = null): array
    {
        if (MailTemplate::hasBuiltIn($locale)) {
            return ['subject' => $template->defaultSubject($locale), 'body' => $template->defaultBody($locale)];
        }

        $default ??= self::get($template, Locales::default());

        return ['subject' => $default['subject'], 'body' => $default['body']];
    }

    public static function enabled(MailTemplate $template): bool
    {
        return self::get($template)['enabled'];
    }

    public static function customized(MailTemplate $template): bool
    {
        return EmailTemplate::query()->whereKey($template->value)->where(fn ($q) => $q->whereNotNull('subject')->orWhereNotNull('body'))->exists();
    }

    /**
     * Store an edit; the built-in text (or a blank) is stored as null, and a row with nothing left is removed.
     * In another language its fallback (built-in or the default language's text) counts as "nothing", and the on/off
     * switch is shared.
     */
    public static function save(MailTemplate $template, ?string $subject, ?string $body, ?bool $enabled = null, ?string $locale = null): void
    {
        $own = fn (?string $value, string $default) => ($value = trim((string) $value)) === '' || $value === $default ? null : $value;

        if (! Locales::isDefault($locale)) {
            $default = self::get($template, Locales::default());
            $fallback = self::fallback($template, Locales::normalize($locale), $default);
            $values = ['subject' => $own($subject, $fallback['subject']), 'body' => $own($body, $fallback['body'])];
            $keys = ['key' => $template->value, 'locale' => Locales::normalize($locale)];

            array_filter($values, fn ($v) => $v !== null) === []
                ? EmailTemplateTranslation::query()->where($keys)->delete()
                : EmailTemplateTranslation::query()->updateOrCreate($keys, $values);

            self::save($template, $default['subject'], $default['body'], $enabled);

            return;
        }

        $values = [
            'subject' => $own($subject, $template->defaultSubject(Locales::default())),
            'body' => $own($body, $template->defaultBody(Locales::default())),
            'enabled' => $enabled === null || $enabled === $template->enabledByDefault() ? null : $enabled,
        ];

        if (array_filter($values, fn ($v) => $v !== null) === []) {
            EmailTemplate::query()->whereKey($template->value)->delete();

            return;
        }

        EmailTemplate::query()->updateOrCreate(['key' => $template->value], $values);
    }

    private static function translation(MailTemplate $template, string $locale): ?EmailTemplateTranslation
    {
        return EmailTemplateTranslation::query()->where(['key' => $template->value, 'locale' => $locale])->first();
    }

    /**
     * Fill in the placeholders. Body values are HTML-escaped (line breaks kept) unless they are
     * Htmlable (tables, the signature), which go in as written.
     *
     * @param  array<string, string|Htmlable|null>  $values
     * @param  array{subject: string, body: string}|null  $text  unsaved text to use instead (preview)
     * @param  string|null  $locale  the language of the stored text (default: the current one)
     * @return array{subject: string, body: string}
     */
    public static function render(MailTemplate $template, array $values, ?array $text = null, ?string $locale = null): array
    {
        $text ??= self::get($template, $locale);

        return [
            'subject' => trim(preg_replace('/\s+/', ' ', self::replace(
                $text['subject'],
                $values,
                fn ($v) => $v instanceof Htmlable ? strip_tags($v->toHtml()) : (string) $v,
            ))),
            'body' => self::fill($text['body'], $values),
        ];
    }

    /**
     * Fill in the placeholders of body text (a template body, the mail header or footer).
     *
     * @param  array<string, string|Htmlable|null>  $values
     */
    public static function fill(string $text, array $values): string
    {
        return self::replace($text, $values, fn ($v) => $v instanceof Htmlable ? $v->toHtml() : nl2br(e((string) $v), false));
    }

    /** @param  array<string, string|Htmlable|null>  $values */
    private static function replace(string $text, array $values, callable $value): string
    {
        return preg_replace_callback(
            '/\{\$([a-z_]+)\}/',
            fn (array $m) => array_key_exists($m[1], $values) ? $value($values[$m[1]]) : $m[0],
            $text,
        );
    }

    /** @return array<string, string|HtmlString|null> the client and company placeholders every email has */
    public static function common(Client $client): array
    {
        $company = config('gaiacp.company');
        $instructions = PaymentMethods::instructions();

        return [
            'client_first_name' => $client->first_name,
            'client_last_name' => $client->last_name,
            'client_name' => $client->name,
            'client_company' => $client->company,
            'client_email' => $client->email,
            'company_name' => $company['name'] ?? null,
            'company_email' => $company['email'] ?? null,
            'company_phone' => $company['phone'] ?? null,
            'company_website' => $company['website'] ?? null,
            'signature' => new HtmlString(collect([$company['name'] ?? null, $company['phone'] ?? null, $company['email'] ?? null, $company['website'] ?? null])
                ->filter()->map(fn ($line) => e($line))->join("<br>\n")),
            'payment_instructions' => new HtmlString($instructions ? nl2br(e($instructions), false) : ''),
        ];
    }

    /**
     * A Markdown table for a placeholder; cells are escaped. The last column is right-aligned (amounts).
     *
     * @param  list<string>  $headers
     * @param  iterable<list<string>>  $rows
     */
    public static function table(array $headers, iterable $rows): HtmlString
    {
        $cell = fn (?string $value) => str_replace(['|', "\n"], ['\|', ' '], e((string) $value));
        $line = fn (array $cells) => '| '.implode(' | ', array_map($cell, $cells)).' |';

        $lines = [$line($headers), '|'.str_repeat(':--|', count($headers) - 1).'--:|'];
        foreach ($rows as $row) {
            $lines[] = $line($row);
        }

        // The div gives the table the mail theme's table styling, like <x-mail::table>.
        return new HtmlString("<div class=\"table\">\n\n".implode("\n", $lines)."\n\n</div>");
    }
}
