<?php

namespace App\Filament\Resources\CancellationRequests;

use App\Enums\AdminArea;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\CancellationRequests\Pages\ListCancellationRequests;
use App\Filament\Resources\CancellationRequests\Tables\CancellationRequestsTable;
use App\Models\CancellationRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Services → Cancellation requests: what clients asked to cancel from the client area, to approve or decline (App\Actions\CancellationRequests). */
class CancellationRequestResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = CancellationRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedXCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static ?int $navigationSort = 7;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Services;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = CancellationRequest::query()->pending()->count();

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
        return CancellationRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCancellationRequests::route('/'),
        ];
    }
}
