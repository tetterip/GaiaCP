<?php

namespace App\Support;

use App\Providers\AppServiceProvider;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The terms of service clients accept (Settings → Client area → Terms of Service): a link to them or their text
 * (Markdown), and a version. While it is on, the client area's Order page and Sign up ask for "I accept the Terms of
 * Service" and the order or the client keeps when and which version was accepted (terms_accepted_at / terms_version).
 */
class TermsOfService
{
    /** Whether clients must accept them: switched on, with a link or a text to show. */
    public static function required(): bool
    {
        return (bool) config('gaiacp.client_area.terms') && (self::url() !== null || self::text() !== null);
    }

    public static function url(): ?string
    {
        return filled($url = config('gaiacp.client_area.terms_url')) ? (string) $url : null;
    }

    public static function text(): ?string
    {
        return filled($text = config('gaiacp.client_area.terms_text')) ? (string) $text : null;
    }

    /** What an acceptance records: the version the admin wrote, or the date and time the terms last changed. */
    public static function version(): ?string
    {
        return filled($version = config('gaiacp.client_area.terms_version')) ? (string) $version : null;
    }

    /** A version for terms that just changed: the date and time, in the app's format. */
    public static function newVersion(): string
    {
        return now()->format(AppServiceProvider::dateFormat().' H:i');
    }

    /** @return array{terms_accepted_at: Carbon, terms_version: string|null} the columns an accepted order or sign up keeps */
    public static function acceptance(): array
    {
        return ['terms_accepted_at' => now(), 'terms_version' => self::version()];
    }

    /** "Accepted on 17/10/2026 15:40, version 2.0" for the admin's order and client pages. */
    public static function describeAcceptance(?DateTimeInterface $at, ?string $version, bool $signup = false): ?string
    {
        if ($at === null) {
            return null;
        }

        $when = Carbon::instance($at)->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat().' H:i');

        return match (true) {
            $signup && filled($version) => __('Accepted the terms of service when signing up on :date, version :version.', ['date' => $when, 'version' => $version]),
            $signup => __('Accepted the terms of service when signing up on :date.', ['date' => $when]),
            filled($version) => __('Accepted on :date, version :version', ['date' => $when, 'version' => $version]),
            default => __('Accepted on :date', ['date' => $when]),
        };
    }

    /** The checkbox for a client area form, with a link that opens the terms (their page in a new tab, or their text in a window). */
    public static function checkbox(): Checkbox
    {
        return Checkbox::make('accept_terms')
            ->label('I accept the Terms of Service')
            ->accepted()
            ->validationMessages(['accepted' => __('Please accept the Terms of Service to continue.')])
            ->dehydrated()
            ->visible(fn () => self::required())
            ->hintAction(
                Action::make('readTerms')
                    ->label(__('Read the Terms of Service'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn () => self::text() === null ? self::url() : null, shouldOpenInNewTab: true)
                    ->modalHeading(__('Terms of Service'))
                    ->modalContent(fn () => self::text() === null ? null : self::html())
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->slideOver(),
            );
    }

    /** The text as HTML (Markdown, HTML in it stripped), with a link to the full page when there is one too. */
    public static function html(): HtmlString
    {
        $html = Str::markdown((string) self::text(), ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        if (self::url() !== null) {
            $html .= '<p><a href="'.e(self::url()).'" target="_blank" rel="noopener">'.e(__('Open the full Terms of Service')).'</a></p>';
        }

        return new HtmlString('<div class="fi-prose">'.$html.'</div>');
    }
}
