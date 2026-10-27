<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

class DomainsRelationManager extends ServicesRelationManager
{
    protected const TAB = 'domains';

    protected static ?string $title = 'Domains';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedGlobeAlt;
}
