<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Client;
use App\Models\Ticket;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The client's support tickets, newest first; each opens on its own page. */
class TicketsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'tickets';

    protected static ?string $title = 'Tickets';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedLifebuoy;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TicketResource::canAccess();
    }

    /** The ones waiting for us. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Client $ownerRecord */
        $count = $ownerRecord->tickets()->waiting()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return __('Waiting for us');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return TicketsTable::configure($table, showClient: false)
            ->recordUrl(fn (Ticket $record) => TicketResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('openTicket')
                    ->label('Open a ticket')
                    ->icon(Heroicon::OutlinedLifebuoy)
                    ->url(fn () => TicketResource::getUrl('create', ['client' => $this->getOwnerRecord()->getKey()])),
            ]);
    }
}
