<?php

namespace App\Mail;

use App\Support\Branding;
use App\Support\MailTemplates;
use Illuminate\Contracts\Support\Htmlable;

/**
 * What wraps every client email (Settings → Mail): the company logo (Settings → Company) or name on top, the header
 * and footer texts around the template's text, and the company line at the bottom.
 */
class MailLayout
{
    /**
     * The view data for `mail.template`.
     *
     * @param  array<string, string|Htmlable|null>  $placeholders  filled into the header and footer
     * @return array<string, mixed>
     */
    public static function data(string $body, array $placeholders): array
    {
        $mail = (array) config('gaiacp.mail');

        return [
            'body' => $body,
            'header' => filled($mail['header'] ?? null) ? MailTemplates::fill($mail['header'], $placeholders) : null,
            'footer' => filled($mail['footer'] ?? null) ? MailTemplates::fill($mail['footer'], $placeholders) : null,
        ];
    }

    /**
     * The company logo for `x-client-mail`, embedded in the message (cid:) so mail clients show it without loading
     * remote images. $message is a Message (sending, or a preview, where Laravel turns cid: into a data: URI) or a
     * TextMessage for the plain-text part, which embeds nothing; null when there is no logo to show.
     */
    public static function companyLogo(mixed $message): ?string
    {
        $logo = self::logoPath();

        return $logo === null ? null : ($message ? $message->embed($logo) : self::dataUri($logo));
    }

    public static function companyName(): string
    {
        return config('gaiacp.company.name') ?: config('app.name');
    }

    public static function companyUrl(): string
    {
        return config('gaiacp.company.website') ?: config('app.url');
    }

    /**
     * The GaiaCP logo for the admins' system emails (`x-admin-mail`): embedded like the company logo, as a data: URI
     * in a preview, and '' in the plain-text part, which shows the name instead.
     */
    public static function gaiacpLogo(mixed $message): string
    {
        $logo = public_path('images/gaiacp/logo-email.png');

        return $message ? $message->embed($logo) : self::dataUri($logo);
    }

    /** Absolute path of the company logo (a PNG copy of an SVG), or null when there is none to show. */
    public static function logoPath(): ?string
    {
        return Branding::emailLogoPath();
    }

    private static function dataUri(string $path): string
    {
        return 'data:'.(mime_content_type($path) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($path));
    }
}
