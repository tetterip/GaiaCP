<?php

namespace App\Filament\Clusters\Settings;

/** A page or resource shown as a card on the Settings page. */
interface SettingsCard
{
    /** One line under the card's title: what is set there. */
    public static function settingsDescription(): string;
}
