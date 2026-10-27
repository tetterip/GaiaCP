<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Services\Tables\ServicesTable;
use App\Models\Client;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One kind of the client's services (a ListServices::TABS key in TAB), as a tab of the client page; creating and
 * editing happen on the service pages so there is a single form to maintain.
 */
abstract class ServicesRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    /** A ListServices::TABS key. */
    protected const TAB = '';

    protected static string $relationship = 'services';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ServiceResource::canAccess();
    }

    /** What the tab's table lists by default: the services running or about to. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Client $ownerRecord */
        $count = $ownerRecord->services()
            ->whereIn('type', ListServices::TABS[static::TAB])
            ->whereIn('status', ServicesTable::OPEN)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return ServicesTable::configure($table, showClient: false, tab: static::TAB)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('type', ListServices::TABS[static::TAB]))
            ->headerActions([
                Action::make('newOrder')
                    ->label('New order')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->visible(fn () => OrderResource::canAccess())
                    ->url(fn () => OrderResource::getUrl('create', ['client' => $this->getOwnerRecord()->getKey()])),
                Action::make('newService')
                    ->label('New service')
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(fn () => ServiceResource::getUrl('create', ['client' => $this->getOwnerRecord()->getKey()])),
            ]);
    }
}
