<?php

namespace App\Filament\Client\Resources\Tickets;

use App\Enums\TicketStatus;
use App\Filament\Client\Concerns\ScopedToClient;
use App\Filament\Client\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Client\Resources\Tickets\Pages\ListTickets;
use App\Filament\Client\Resources\Tickets\Pages\ViewTicket;
use App\Models\Ticket;
use App\Providers\AppServiceProvider;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Support → Tickets in the client area (Settings → Support → Clients open tickets): the client's own tickets, a new
 * one, and each ticket's messages (never the admins' notes) with Reply and Close (App\Actions\Tickets).
 */
class TicketResource extends Resource
{
    use ScopedToClient;

    protected static ?string $model = Ticket::class;

    protected static ?string $slug = 'tickets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'subject';

    public static function getNavigationGroup(): string
    {
        return __('Support');
    }

    public static function getNavigationLabel(): string
    {
        return __('Tickets');
    }

    public static function canAccess(): bool
    {
        return (bool) config('gaiacp.support.client_tickets') && static::clientId() !== null;
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    /** Answered ones wait for the client. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', TicketStatus::Answered)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Answered');
    }

    protected static function scopeToClient(Builder $query): Builder
    {
        return $query->where('client_id', static::clientId());
    }

    public static function getRecordTitle(?Model $record): string
    {
        /** @var Ticket|null $record */
        return $record ? "{$record->number} · {$record->subject}" : '';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('department'))
            ->columns([
                TextColumn::make('subject')
                    ->description(fn (Ticket $record) => $record->number)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('department.name')
                    ->label('Department')
                    ->placeholder('–'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('last_reply_at')
                    ->label('Last message')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('last_reply_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(TicketStatus::class),
            ])
            // The chips over the table (ListTickets) set it
            ->filtersLayout(FiltersLayout::Hidden)
            ->hiddenFilterIndicators()
            ->recordActions([])
            ->emptyStateHeading(__('No tickets yet'))
            ->emptyStateDescription(__('Need help? Open a ticket and we will answer here and by email.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])
                ->columnSpanFull()
                ->schema([
                    View::make('filament.tickets.thread')
                        ->viewData(['panel' => 'client'])
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    Section::make()
                        ->columnSpan(1)
                        ->schema([
                            TextEntry::make('status')->badge(),
                            TextEntry::make('department.name')->label('Department')->placeholder('–'),
                            TextEntry::make('priority')->badge()->visible(fn () => (bool) config('gaiacp.support.client_priority')),
                            TextEntry::make('service')
                                ->label('Service')
                                ->state(fn (Ticket $record) => $record->service ? trim($record->service->label.' '.($record->service->domain !== $record->service->label ? $record->service->domain : '')) : null)
                                ->placeholder('–'),
                            TextEntry::make('created_at')
                                ->label('Opened')
                                ->dateTime(AppServiceProvider::dateFormat().' H:i'),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'create' => CreateTicket::route('/create'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
