<?php

namespace App\Filament\Resources\UpgradeRequests;

use App\Enums\AdminArea;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\UpgradeRequests\Pages\ListUpgradeRequests;
use App\Filament\Resources\UpgradeRequests\Tables\UpgradeRequestsTable;
use App\Models\UpgradeRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Services → Upgrade requests: the products clients asked to move to from the client area, to approve or decline (App\Actions\UpgradeRequests). */
class UpgradeRequestResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = UpgradeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static ?int $navigationSort = 6;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Services;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = UpgradeRequest::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Waiting for approval');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return UpgradeRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUpgradeRequests::route('/'),
        ];
    }
}
