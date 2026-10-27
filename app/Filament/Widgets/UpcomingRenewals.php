<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Services\Tables\ServicesTable;
use App\Filament\Widgets\Concerns\FitsDashboard;
use App\Models\Service;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class UpcomingRenewals extends TableWidget
{
    use FitsDashboard;

    protected static ?int $sort = 3;

    public static function dashboardLabel(): string
    {
        return __('Overdue and due in the next 30 days');
    }

    public static function canView(): bool
    {
        return ServiceResource::canAccess();
    }

    public function table(Table $table): Table
    {
        $table = ServicesTable::configure($table)
            ->heading(__('Overdue and due in the next 30 days'))
            ->query(fn () => Service::query()->billable()->with('client')->whereDate('next_due_date', '<=', today()->addDays(30)))
            ->filters([])
            // A row opens the service; Record payment is the one action here
            ->recordActions([RecordPaymentAction::make()])
            ->paginated([10, 25, 50]);

        // Narrower than the services list: the type and server are left out
        $table->getColumn('type')?->hidden();
        $table->getColumn('server.name')?->hidden();

        return $table;
    }
}
