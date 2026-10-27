<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Orders\Concerns\HasStatusChips;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Client;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The client's orders, newest first, with the status chips; each opens on its own page. */
class OrdersRelationManager extends RelationManager
{
    use HasStatusChips;
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'orders';

    protected static ?string $title = 'Orders';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedShoppingCart;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return OrderResource::canAccess();
    }

    /** The pending ones, still to complete. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Client $ownerRecord */
        $count = $ownerRecord->orders()->where('status', OrderStatus::Pending)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return __('Pending');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    protected function statusChipsQuery(): Builder
    {
        return $this->getOwnerRecord()->orders()->getQuery();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->statusChipsComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_AFTER),
            ]);
    }

    public function table(Table $table): Table
    {
        return OrdersTable::configure($table, showClient: false)
            ->headerActions([
                Action::make('newOrder')
                    ->label('New order')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->url(fn () => OrderResource::getUrl('create', ['client' => $this->getOwnerRecord()->getKey()])),
            ]);
    }
}
