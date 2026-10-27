<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/** A relation manager's tab title through __(). */
trait TranslatesRelationManagerTitle
{
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __(parent::getTitle($ownerRecord, $pageClass));
    }
}
