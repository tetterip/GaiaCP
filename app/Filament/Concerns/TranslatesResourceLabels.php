<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Str;
use UnitEnum;

use function Filament\Support\get_model_label;

/** A resource's record names ("client", "clients"), menu label and group through __(). */
trait TranslatesResourceLabels
{
    public static function getModelLabel(): string
    {
        return __(parent::getModelLabel());
    }

    public static function getPluralModelLabel(): string
    {
        // Made plural in English, before translating
        return __(static::$pluralModelLabel ?? Str::plural(static::$modelLabel ?? get_model_label(static::getModel())));
    }

    /** Capitalized like a sentence ("Payment notices"), not every word, which reads wrong in Greek. */
    public static function getTitleCaseModelLabel(): string
    {
        return Str::ucfirst(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }

    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = parent::getNavigationGroup();

        return is_string($group) ? __($group) : $group;
    }
}
