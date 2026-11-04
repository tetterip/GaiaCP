<?php

namespace App\Filament\Resources\ModuleQueue;

use App\Enums\AdminArea;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\ModuleQueue\Pages\ListModuleQueue;
use App\Filament\Resources\ModuleQueue\Tables\ModuleQueueTable;
use App\Models\ModuleQueueItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Services → Module queue: panel and registrar actions that failed, to retry or mark resolved (App\Actions\ModuleQueue). */
class ModuleQueueItemResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = ModuleQueueItem::class;

    protected static ?string $modelLabel = 'failed action';

    protected static ?string $navigationLabel = 'Module queue';

    protected static ?string $slug = 'module-queue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static ?int $navigationSort = 8;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Services;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = ModuleQueueItem::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Failed actions to retry');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ModuleQueueTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModuleQueue::route('/'),
        ];
    }
}
