<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\User;

/**
 * What's new: after an update, a small window in /admin with this version's one or two highlights (a picture, a title,
 * a sentence and a link), resources/views/filament/whats-new.blade.php. Admins only, once each
 * (`users.whats_new_version`, set when it opens), and again from the user menu → What's new. Someone who skipped
 * versions sees only the latest one's. A user added after an update starts at that version, and an admin who has not
 * had the welcome tour yet gets the tour instead, so new installs never see it.
 *
 * The highlights are written with the release's short notes (docs/release-notes/<version>.md, docs/releases.md); a
 * version without any shows nothing.
 */
class WhatsNew
{
    /**
     * The current version's highlights; empty when it has none or the user can't open what they are about.
     *
     * @return list<array{title: string, text: string, image: string|null, url: string|null, link: string|null}>
     */
    public static function highlights(): array
    {
        return array_values(array_filter(self::versions()[self::version()] ?? []));
    }

    /** Whether the signed-in user is an admin and this version has something to show them. */
    public static function enabled(): bool
    {
        $user = filament()->auth()->user();

        return filament()->getId() === 'admin' && $user instanceof User && $user->role === UserRole::Admin && self::highlights() !== [];
    }

    /** Whether it opens by itself on this page: once per admin and version, which is noted now. */
    public static function opensNow(): bool
    {
        if (! self::enabled()) {
            return false;
        }

        /** @var User $user */
        $user = filament()->auth()->user();

        if ($user->whats_new_version === self::version()) {
            return false;
        }

        // The welcome tour opens on an admin's first visit (noted just before, in this same request) and is enough for that day
        $tour = $user->admin_tour_seen_at === null || $user->wasChanged('admin_tour_seen_at');

        $user->forceFill(['whats_new_version' => self::version()])->save();

        return ! $tour;
    }

    /** The version as released: 0.12.0 for 0.12.0-beta.1 too. */
    public static function version(): string
    {
        return explode('-', Version::current(), 2)[0];
    }

    /** @return array<string, list<array{title: string, text: string, image: string|null, url: string|null, link: string|null}|null>> */
    private static function versions(): array
    {
        return [
            '0.12.0' => [
                self::highlight(
                    __('Quarantine'),
                    __('Domains that expired but are still inside their grace days, and hosting suspended for a while, now show as Quarantine. Hosting and Domains open on the active ones and those in quarantine, so what can still be saved comes first.'),
                    'filament.whats-new.quarantine',
                    ServiceResource::canAccess() ? ServiceResource::getUrl(parameters: ['tab' => 'domains']) : null,
                    __('Open Domains'),
                ),
            ],
        ];
    }

    /** @return array{title: string, text: string, image: string|null, url: string|null, link: string|null} */
    private static function highlight(string $title, string $text, ?string $image = null, ?string $url = null, ?string $link = null): array
    {
        return ['title' => $title, 'text' => $text, 'image' => $image, 'url' => $url, 'link' => $url === null ? null : $link];
    }
}
