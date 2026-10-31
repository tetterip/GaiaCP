<?php

namespace App\Filament\Resources\ClientMessages;

use App\Enums\AdminArea;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\ClientMessages\Pages\CreateClientMessage;
use App\Filament\Resources\ClientMessages\Pages\EditClientMessage;
use App\Filament\Resources\ClientMessages\Pages\ListClientMessages;
use App\Filament\Resources\ClientMessages\RelationManagers\RecipientsRelationManager;
use App\Filament\Resources\ClientMessages\Schemas\ClientMessageForm;
use App\Filament\Resources\ClientMessages\Tables\ClientMessagesTable;
use App\Models\ClientMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Clients → Messages to clients: an email to the clients its filters match (sent a few a minute, App\Actions\
 * SendClientMessages) and/or an announcement on their client area Home.
 */
class ClientMessageResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = ClientMessage::class;

    protected static ?string $modelLabel = 'message';

    protected static ?string $navigationLabel = 'Messages to clients';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Main';

    protected static ?int $navigationSort = 4;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Clients;
    }

    public static function form(Schema $schema): Schema
    {
        return ClientMessageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientMessagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [RecipientsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClientMessages::route('/'),
            'create' => CreateClientMessage::route('/create'),
            'edit' => EditClientMessage::route('/{record}/edit'),
        ];
    }
}
