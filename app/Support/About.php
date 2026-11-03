<?php

namespace App\Support;

use Composer\InstalledVersions;
use NumberFormatter;
use Throwable;

/**
 * The admin's About window (resources/views/filament/about.blade.php): opened from the version in the footer or the
 * user menu. It also holds a small surprise for whoever knows the old cheat code.
 */
final class About
{
    /** The keys that start the surprise, as `KeyboardEvent.key` names them (letters in lower case). */
    public const SECRET = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'];

    /** Taps on the hexagon in the About window that start it too, for phones without arrow keys. */
    public const TAPS = 7;

    /** @return array<string, string> what GaiaCP runs on, label => version */
    public static function stack(): array
    {
        return [
            'PHP' => PHP_VERSION,
            'Laravel' => app()->version(),
            'Filament' => self::package('filament/filament'),
        ];
    }

    /** The base currency's symbol (€ for EUR), for the coins of the surprise; the code when there is none. */
    public static function coin(): string
    {
        $code = Currencies::base();

        try {
            $symbol = class_exists(NumberFormatter::class)
                ? (new NumberFormatter('en@currency='.$code, NumberFormatter::CURRENCY))->getSymbol(NumberFormatter::CURRENCY_SYMBOL)
                : '';
        } catch (Throwable) {
            $symbol = '';
        }

        return $symbol !== '' && mb_strlen($symbol) <= 3 ? $symbol : $code;
    }

    /**
     * Gaia's family tree (Hesiod's Theogony, trimmed), shown after the surprise: one row per generation, each member
     * with what the myths made them god of and the part of GaiaCP that does the same job. Texts are English keys
     * for __(); `icon` names a pictogram in resources/views/filament/about-family.blade.php.
     *
     * @return list<array{caption: string, members: list<array{icon: string, name: string, myth: string, job: string}>}>
     */
    public static function family(): array
    {
        $member = fn (string $icon, string $name, string $myth, string $job): array => compact('icon', 'name', 'myth', 'job');

        return [
            ['caption' => 'In the beginning', 'members' => [
                $member('chaos', 'Chaos', 'The void before everything', 'Life before GaiaCP: spreadsheets'),
            ]],
            ['caption' => 'Out of Chaos came', 'members' => [
                $member('gaia', 'Gaia', 'Mother Earth, the first of all', 'GaiaCP itself'),
            ]],
            ['caption' => 'Gaia gave birth to', 'members' => [
                $member('uranus', 'Uranus', 'The starry sky', 'The cloud your hosting lives in'),
                $member('pontus', 'Pontus', 'The sea', 'Networks and nameservers'),
            ]],
            ['caption' => 'Gaia and Uranus: the Titans', 'members' => [
                $member('oceanus', 'Oceanus', 'The river around the world', 'Domains, all around the world'),
                $member('hyperion', 'Hyperion', 'Light that watches', 'System health'),
                $member('themis', 'Themis', 'Law and order', 'Terms, tax and fair rules'),
                $member('mnemosyne', 'Mnemosyne', 'Memory', 'The activity log, which forgets nothing'),
                $member('cronus', 'Cronus', 'Time', 'The scheduler (cron), every minute'),
                $member('rhea', 'Rhea', 'Mother of the gods', 'Backups: she saved Zeus, they save you'),
            ]],
            ['caption' => 'Cronus and Rhea: the first Olympians', 'members' => [
                $member('hestia', 'Hestia', 'The hearth', 'The dashboard, your home'),
                $member('demeter', 'Demeter', 'The harvest', 'Reports'),
                $member('hera', 'Hera', 'Family', 'Clients and their accounts'),
                $member('hades', 'Hades', 'The underworld', 'Suspended and terminated services'),
                $member('poseidon', 'Poseidon', 'The sea and earthquakes', 'Uptime monitors'),
                $member('zeus', 'Zeus', 'King of the gods', 'The super admin'),
            ]],
            ['caption' => 'The next generation', 'members' => [
                $member('athena', 'Athena', 'Wisdom', 'The AI helpers'),
                $member('apollo', 'Apollo', 'Light, music and truth', 'The knowledge base'),
                $member('artemis', 'Artemis', 'The hunt', 'Leads'),
                $member('hermes', 'Hermes', 'Messenger and trade', 'Payments and emails'),
                $member('hephaestus', 'Hephaestus', 'The forge', 'Servers and provisioning'),
                $member('ares', 'Ares', 'War', 'Login security and IP bans'),
                $member('dionysus', 'Dionysus', 'Wine and celebration', 'Coupons'),
            ]],
        ];
    }

    private static function package(string $name): string
    {
        try {
            return ltrim((string) InstalledVersions::getPrettyVersion($name), 'v') ?: '?';
        } catch (Throwable) {
            return '?';
        }
    }
}
