<?php

namespace App\Filament\Resources\Tickets;

use App\Enums\AdminArea;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Models\Ticket;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Support → Tickets (App\Actions\Tickets): the clients' tickets, the ones waiting on us first. A ticket's page shows
 * its messages beside the client's services and what they owe, with Reply, Add note, Change and Close.
 */
class TicketResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'subject';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Support;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = Ticket::query()->waiting()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Waiting for us');
    }

    public static function getRecordTitle(?Model $record): string
    {
        /** @var Ticket|null $record */
        return $record ? "{$record->number} · {$record->subject}" : '';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['subject'];
    }

    public static function table(Table $table): Table
    {
        return TicketsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])
                ->columnSpanFull()
                ->schema([
                    View::make('filament.tickets.thread')
                        ->viewData(['panel' => 'admin'])
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    Section::make()
                        ->columnSpan(1)
                        ->schema([
                            TextEntry::make('client.name')
                                ->label('Client')
                                ->state(fn (Ticket $record) => $record->client?->name)
                                ->placeholder(__('Not linked yet: Change → Client'))
                                ->url(fn (Ticket $record) => $record->client_id && ClientResource::canAccess() ? ClientResource::getUrl('edit', ['record' => $record->client_id]) : null),
                            TextEntry::make('email')
                                ->label('Emailed from')
                                ->state(fn (Ticket $record) => trim(($record->name ? $record->name.' ' : '').'<'.$record->email.'>'))
                                ->visible(fn (Ticket $record) => filled($record->email) && $record->client?->email !== $record->email),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('priority')->badge(),
                            TextEntry::make('department.name')->label('Department')->placeholder('–'),
                            TextEntry::make('assignee.name')->label('Assigned to')->placeholder(__('Nobody')),
                            TextEntry::make('service')
                                ->label('Service')
                                ->state(fn (Ticket $record) => $record->service ? trim($record->service->label.' '.($record->service->domain !== $record->service->label ? $record->service->domain : '')) : null)
                                ->url(fn (Ticket $record) => $record->service_id && ServiceResource::canAccess() ? ServiceResource::getUrl('edit', ['record' => $record->service_id]) : null)
                                ->placeholder('–'),
                            TextEntry::make('client_services')
                                ->label('Client\'s services')
                                ->state(fn (Ticket $record) => $record->client?->services()->billable()->orderBy('label')->limit(8)->get()
                                    ->map(fn ($service) => trim($service->label.' '.($service->domain !== $service->label ? $service->domain : '')).' · '.$service->status->getLabel())
                                    ->all() ?: null)
                                ->listWithLineBreaks()
                                ->placeholder('–'),
                            TextEntry::make('owed')
                                ->label('Owes')
                                ->state(fn (Ticket $record) => self::owed($record))
                                ->color(fn (Ticket $record) => $record->client?->invoices()->unpaid()->whereDate('due_date', '<', today())->exists() ? 'danger' : null)
                                ->placeholder('–'),
                            TextEntry::make('created_at')
                                ->label('Opened')
                                ->dateTime(AppServiceProvider::dateFormat().' H:i'),
                        ]),
                ]),
        ]);
    }

    /** What the client owes on unpaid payment notices, one amount per currency. */
    private static function owed(Ticket $record): ?string
    {
        $unpaid = $record->client?->invoices()->unpaid()->get(['currency', 'total_cents']);

        return $unpaid?->isNotEmpty()
            ? $unpaid->groupBy('currency')->map(fn ($group, $currency) => Money::format($group->sum('total_cents'), $currency))->join(' + ')
            : null;
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
