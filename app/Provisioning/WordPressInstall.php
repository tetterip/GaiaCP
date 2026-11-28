<?php

namespace App\Provisioning;

/** What a new WordPress site is installed with (ManagesWordPress::installWordPress()). */
final class WordPressInstall
{
    /**
     * @param  string  $path  the folder under the domain's website, '' for the domain itself
     * @param  string  $language  a WordPress locale, e.g. en_US, el
     */
    public function __construct(
        public readonly string $path,
        public readonly string $title,
        public readonly string $username,
        public readonly string $password,
        public readonly string $email,
        public readonly string $language = 'en_US',
    ) {}

    /** WordPress's locale for a GaiaCP language (en, el, de, pt_BR, …). */
    public static function languageFor(string $locale): string
    {
        return match ($locale) {
            'en' => 'en_US',
            'el', 'pt_BR' => $locale,
            'de', 'es', 'fr', 'it', 'nl', 'pl', 'tr' => $locale.'_'.strtoupper($locale),
            default => str_contains($locale, '_') ? $locale : 'en_US',
        };
    }
}
