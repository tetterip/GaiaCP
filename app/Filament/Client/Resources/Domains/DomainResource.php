<?php

namespace App\Filament\Client\Resources\Domains;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Client\Concerns\ScopedToClient;
use App\Filament\Client\Pages\Order;
use App\Filament\Client\Resources\Domains\Pages\ListDomains;
use App\Filament\Client\Resources\Domains\Pages\ViewDomain;
use App\Filament\Client\Resources\Services\ServiceResource;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use BackedEnum;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The client's domains (services of type domain shown in the client area): expiry, nameservers, auto renew. Read-only. */
class DomainResource extends Resource
{
    use ScopedToClient;

    protected static ?string $model = Service::class;

    protected static ?string $modelLabel = 'domain';

    protected static ?string $slug = 'domains';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string
    {
        return __('Services');
    }

    protected static ?string $recordTitleAttribute = 'domain';

    protected static function scopeToClient(Builder $query): Builder
    {
        return $query->where('client_id', static::clientId())
            ->clientVisible()
            ->where('type', ServiceType::Domain)
            ->where('status', '!=', ServiceStatus::Cancelled);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('domainDetails'))
            ->columns([
                TextColumn::make('domain')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('domainDetails.expires_at')
                    ->label('Expires')
                    ->date(AppServiceProvider::dateFormat())
                    ->placeholder('—'),
                TextColumn::make('next_due_date')
                    ->label('Next due date')
                    ->date(AppServiceProvider::dateFormat())
                    ->color(fn (Service $record) => $record->isOverdue() ? 'danger' : null)
                    ->sortable(),
                IconColumn::make('auto_renew')
                    ->label('Auto renew')
                    ->state(fn (Service $record) => $record->auto_renew)
                    ->boolean(),
            ])
            ->defaultSort('domain')
            // Set by the chips over the table (ListDomains), which show which one is on
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ServiceStatus::cases())->reject(fn ($s) => $s === ServiceStatus::Cancelled)
                        ->mapWithKeys(fn (ServiceStatus $s) => [$s->value => $s->getLabel()])),
            ])
            ->filtersLayout(FiltersLayout::Hidden)
            ->hiddenFilterIndicators()
            ->recordActions([])
            ->emptyStateHeading(__('No domains yet'))
            ->emptyStateActions([Order::callToAction(domain: true)]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('domainDetails.registered_at')
                        ->label('Registered')
                        ->date(AppServiceProvider::dateFormat())
                        ->placeholder('—'),
                    TextEntry::make('domainDetails.expires_at')
                        ->label('Expires')
                        ->date(AppServiceProvider::dateFormat())
                        ->placeholder('—'),
                    TextEntry::make('next_due_date')
                        ->label('Next due date')
                        ->date(AppServiceProvider::dateFormat())
                        ->color(fn (Service $record) => $record->isOverdue() ? 'danger' : null),
                    TextEntry::make('price_cents')
                        ->label('Renewal price')
                        ->state(fn (Service $record) => ServiceResource::price($record))
                        ->helperText(fn (Service $record) => ServiceResource::newPrice($record)),
                    IconEntry::make('auto_renew')
                        ->label('Auto renew')
                        ->state(fn (Service $record) => $record->auto_renew)
                        ->boolean()
                        ->helperText(fn (Service $record) => $record->auto_renew ? null : __('Ends on its due date: it will not renew.')),
                    TextEntry::make('domainDetails.nameservers')
                        ->label('Nameservers')
                        ->listWithLineBreaks()
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'view' => ViewDomain::route('/{record}'),
        ];
    }
}
