<?php

namespace App\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/** A page's title, menu label and menu group through __() (they are static strings, written in English). */
trait TranslatesPageLabels
{
    public function getTitle(): string|Htmlable
    {
        $title = parent::getTitle();

        return is_string($title) ? __($title) : $title;
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
