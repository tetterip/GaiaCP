<?php

namespace App\Filament\Clusters\Settings;

use App\Filament\Clusters\Settings\Pages\PaymentMethodsSettings;

/**
 * A payment plugin's settings page: listed on Settings → Payment methods and under it in the sub-navigation,
 * so it has no card of its own on the Settings page.
 */
trait PaymentMethodPage
{
    public static function getNavigationParentItem(): ?string
    {
        return PaymentMethodsSettings::getNavigationLabel();
    }
}
