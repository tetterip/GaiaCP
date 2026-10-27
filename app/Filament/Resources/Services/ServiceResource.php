<?php

namespace App\Filament\Resources\Services;

use App\Enums\AdminArea;
use App\Enums\ServiceType;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\GlobalSearch\ResultTitle;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\RelationManagers\AddonsRelationManager;
use App\Filament\Resources\Services\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Services\Schemas\ServiceForm;
use App\Filament\Resources\Services\Tables\ServicesTable;
use App\Filament\Resources\Shared\ActivityLogsRelationManager;
use App\Models\Service;
use BackedEnum;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use UnitEnum;

use function Filament\Support\original_request;

class ServiceResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?int $globalSearchSort = 2;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Services;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['label', 'domain', 'username', 'client.first_name', 'client.last_name', 'client.company'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('client');
    }

    /** "Label - domain" for hosting and other services, just the domain for domain registrations. */
    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        /** @var Service $record */
        $title = match (true) {
            $record->type === ServiceType::Domain => $record->domain ?: $record->label,
            filled($record->domain) && $record->domain !== $record->label => "{$record->label} - {$record->domain}",
            default => $record->label,
        };

        return ResultTitle::withStatus($title, $record->status);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Service $record */
        return [$record->client->displayName()];
    }

    /**
     * The matching services as models, so the global search can group them by type.
     *
     * @return Collection<int, Service>
     */
    public static function getGlobalSearchRecords(string $search): Collection
    {
        $query = static::getGlobalSearchEloquentQuery();

        static::applyGlobalSearchAttributeConstraints($query, $search);
        static::modifyGlobalSearchQuery($query, $search);

        return $query->limit(static::getGlobalSearchResultsLimit())->get();
    }

    public static function getGlobalSearchResults(string $search): Collection
    {
        return static::getGlobalSearchRecords($search)->map(static::toGlobalSearchResult(...))->filter();
    }

    public static function toGlobalSearchResult(Service $record): ?GlobalSearchResult
    {
        $url = static::getGlobalSearchResultUrl($record);

        return blank($url) ? null : new GlobalSearchResult(
            title: static::getGlobalSearchResultTitle($record),
            url: $url,
            details: static::getGlobalSearchResultDetails($record),
        );
    }

    /** Hosting, Domains and Other services in the sidebar, each a tab of the services list. */
    public static function getNavigationItems(): array
    {
        $items = [
            'hosting' => [__('Hosting'), Heroicon::OutlinedServerStack],
            'domains' => [__('Domains'), Heroicon::OutlinedGlobeAlt],
            'other' => [__('Other services'), Heroicon::OutlinedWrenchScrewdriver],
        ];

        return collect($items)->values()->map(fn (array $item, int $i) => NavigationItem::make($item[0])
            ->key(static::class.'.'.array_keys($items)[$i])
            ->icon($item[1])
            ->group(static::getNavigationGroup())
            ->sort(static::getNavigationSort())
            ->isActiveWhen(fn (): bool => self::activeNavigationTab() === array_keys($items)[$i])
            ->url(static::getUrl('index', ['tab' => array_keys($items)[$i]])))
            ->all();
    }

    /** The sidebar entry of the current services page: its tab, or the type of the service being edited. */
    private static function activeNavigationTab(): ?string
    {
        $request = original_request();

        if (! $request->routeIs(static::getRouteBaseName().'.*')) {
            return null;
        }

        $type = match (true) {
            $request->routeIs(static::getRouteBaseName().'.index') => null,
            $request->route('record') !== null => static::getModel()::query()->whereKey($request->route('record'))->value('type'),
            default => $request->query('type'),
        };

        if ($type === null) {
            return $request->query('tab');
        }

        foreach (ListServices::TABS as $tab => $types) {
            if (in_array($type instanceof ServiceType ? $type : ServiceType::tryFrom((string) $type), $types, true)) {
                return $tab;
            }
        }

        return null;
    }

    public static function form(Schema $schema): Schema
    {
        return ServiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AddonsRelationManager::class,
            PaymentsRelationManager::class,
            ActivityLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
