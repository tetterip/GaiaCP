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
use Filament\Tables\Filters\Filter;
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

    /** Domains ending soon with auto renew off: they will be lost unless the client turns it back on or renews. */
    public static function getNavigationBadge(): ?string
    {
        $count = self::autoRenewOff(static::getEloquentQuery())
            ->whereOnDate('next_due_date', '<=', today()->addDays(self::EXPIRING_DAYS))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Ending soon, auto renew is off');
    }

    /** Days ahead in which a domain counts as expiring soon (its chip and the sidebar badge). */
    public const EXPIRING_DAYS = 30;

    /** Active or suspended domains due within EXPIRING_DAYS, overdue ones too: the ones that need paying or renewing. */
    public static function expiringSoon(Builder $query): Builder
    {
        return $query->whereIn('status', ServiceStatus::billable())
            ->whereOnDate('next_due_date', '<=', today()->addDays(self::EXPIRING_DAYS));
    }

    /** The domain's nameservers under its name in the list ("ns1.host.gr, ns2.host.gr +1"), null before sync knows them. */
    public static function nameserversLine(Service $service): ?string
    {
        $nameservers = array_values(array_filter((array) $service->domainDetails?->nameservers));

        if ($nameservers === []) {
            return null;
        }

        $line = implode(', ', array_slice($nameservers, 0, 2));

        return count($nameservers) > 2 ? __(':list +:count', ['list' => $line, 'count' => count($nameservers) - 2]) : $line;
    }

    /** Whether a billable domain is due within EXPIRING_DAYS (overdue ones too), like the Expiring soon chip. */
    public static function endsSoon(Service $service): bool
    {
        return in_array($service->status, ServiceStatus::billable(), true)
            && $service->next_due_date->lte(today()->addDays(self::EXPIRING_DAYS));
    }

    /** "In 12 days" / "Today" / "5 days ago" under the due date of a domain that ends soon; null otherwise. */
    public static function dueIn(Service $service): ?string
    {
        if (! self::endsSoon($service)) {
            return null;
        }

        $days = (int) today()->diffInDays($service->next_due_date->startOfDay(), false);

        return match (true) {
            $days === 0 => __('Today'),
            $days > 0 => trans_choice('In :count day|In :count days', $days),
            default => trans_choice(':count day ago|:count days ago', -$days),
        };
    }

    /** Active domains the client set not to renew: they end at their due date. */
    public static function autoRenewOff(Builder $query): Builder
    {
        return $query->where('status', ServiceStatus::Active)->where('auto_terminate', true);
    }

    /** The extension of a registered name, everything after its first dot (example.com.gr: com.gr). */
    public static function tldOf(string $domain): string
    {
        return str_contains($domain, '.') ? strtolower(substr($domain, strpos($domain, '.') + 1)) : '';
    }

    /** Domains ending in exactly this extension: .gr leaves out .com.gr. */
    public static function withTld(Builder $query, string $tld): Builder
    {
        return $query->where('domain', 'like', '%.'.$tld)->where('domain', 'not like', '%.%.'.$tld);
    }

    /**
     * The extensions among these domains, most used first.
     *
     * @return list<string>
     */
    public static function tlds(Builder $query): array
    {
        return $query->pluck('domain')
            ->map(fn (?string $domain) => self::tldOf((string) $domain))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->all();
    }

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
                    ->description(fn (Service $record) => self::nameserversLine($record))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('domainDetails.expires_at')
                    ->label('Expires')
                    ->date(AppServiceProvider::dateFormat())
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('next_due_date')
                    ->label('Next due date')
                    ->date(AppServiceProvider::dateFormat())
                    ->description(fn (Service $record) => self::dueIn($record))
                    ->color(fn (Service $record) => $record->isOverdue() ? 'danger' : (self::endsSoon($record) ? 'warning' : null))
                    ->sortable(),
                IconColumn::make('auto_renew')
                    ->label('Auto renew')
                    ->state(fn (Service $record) => $record->auto_renew)
                    ->boolean(),
            ])
            // The one that ends first on top, terminated ones last
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw('case when status = ? then 1 else 0 end', [ServiceStatus::Terminated->value])
                ->orderBy('next_due_date')
                ->orderBy('domain'))
            // Set by the chips over the table (ListDomains), which show which one is on
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ServiceStatus::cases())->reject(fn ($s) => $s === ServiceStatus::Cancelled)
                        ->mapWithKeys(fn (ServiceStatus $s) => [$s->value => $s->getLabel()])),
                Filter::make('expiring')
                    ->label('Expiring soon')
                    ->query(fn (Builder $query) => self::expiringSoon($query)),
                Filter::make('auto_renew_off')
                    ->query(fn (Builder $query) => self::autoRenewOff($query)),
                SelectFilter::make('tld')
                    ->options(fn () => collect(self::tlds(static::getEloquentQuery()))->mapWithKeys(fn (string $tld) => [$tld => '.'.$tld])->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? self::withTld($query, (string) $data['value']) : $query),
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
