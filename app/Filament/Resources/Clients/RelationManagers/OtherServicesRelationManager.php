<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

class OtherServicesRelationManager extends ServicesRelationManager
{
    protected const TAB = 'other';

    protected static ?string $title = 'Other services';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedWrenchScrewdriver;
}
