<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Support\Icons\Heroicon;

/**
 * What's new: after an update, a small window in /admin with what the version is about (the first line of its short
 * release notes, docs/release-notes/<version>.md) under a picture made from its main features, each an icon and a few
 * words (resources/views/filament/whats-new.blade.php), and a link to the release notes. Admins only, once each
 * (`users.whats_new_version`, set when it opens), and again from the user menu → What's new. Someone who skipped
 * versions sees only the latest one's. A user added after an update starts at that version, and an admin who has not
 * had the welcome tour yet gets the tour instead, so new installs never see it. A version with nothing here shows
 * nothing (docs/releases.md, step 2).
 */
class WhatsNew
{
    /**
     * The current version's text and features; null when it has none.
     *
     * @return array{summary: string, features: list<array{icon: Heroicon, label: string}>}|null
     */
    public static function current(): ?array
    {
        return self::versions()[self::version()] ?? null;
    }

    /** Whether the signed-in user is an admin and this version has something to show them. */
    public static function enabled(): bool
    {
        $user = filament()->auth()->user();

        return filament()->getId() === 'admin' && $user instanceof User && $user->role === UserRole::Admin && self::current() !== null;
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

    /** The version's release notes on GitHub. */
    public static function notesUrl(): string
    {
        return Version::REPOSITORY_URL.'/releases/tag/v'.self::version();
    }

    /** @return array<string, array{summary: string, features: list<array{icon: Heroicon, label: string}>}> */
    private static function versions(): array
    {
        return [
            '0.13.0' => [
                'summary' => __('See your servers and hosting accounts at a glance, change hosting plans easily, and keep track of refunds, chargebacks and fees from Stripe and PayPal.'),
                'features' => [
                    self::feature(Heroicon::OutlinedCpuChip, __('Server CPU on the dashboard')),
                    self::feature(Heroicon::OutlinedServerStack, __('The account on the server')),
                    self::feature(Heroicon::OutlinedArrowsUpDown, __('Easier plan changes')),
                    self::feature(Heroicon::OutlinedReceiptRefund, __('Refunds and chargebacks')),
                ],
            ],
            '0.12.0' => [
                'summary' => __('Clients moving from WHMCS keep their password, free orders activate in one click, and System health fixes speed from the panel.'),
                'features' => [
                    self::feature(Heroicon::OutlinedKey, __('WHMCS logins')),
                    self::feature(Heroicon::OutlinedGift, __('Free orders in one click')),
                    self::feature(Heroicon::OutlinedClock, __('Quarantine')),
                    self::feature(Heroicon::OutlinedBolt, __('Speed from the panel')),
                ],
            ],
        ];
    }

    /** @return array{icon: Heroicon, label: string} */
    private static function feature(Heroicon $icon, string $label): array
    {
        return ['icon' => $icon, 'label' => $label];
    }
}
