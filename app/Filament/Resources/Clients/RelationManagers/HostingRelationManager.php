<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

class HostingRelationManager extends ServicesRelationManager
{
    protected const TAB = 'hosting';

    protected static ?string $title = 'Hosting';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedServerStack;
}
