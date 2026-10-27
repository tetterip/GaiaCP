<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Invoices\Concerns\HasStatusChips;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Client;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The client's payment notices, newest first, with the status chips; each opens on its own page. */
class InvoicesRelationManager extends RelationManager
{
    use HasStatusChips;
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Payment notices';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedDocumentText;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return InvoiceResource::canAccess();
    }

    /** The unpaid ones, red when one is overdue. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Client $ownerRecord */
        $count = $ownerRecord->invoices()->unpaid()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Client $ownerRecord */
        return $ownerRecord->invoices()->unpaid()->whereDate('due_date', '<', today())->exists() ? 'danger' : 'warning';
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return __('Unpaid');
    }

    protected function statusChipsQuery(): Builder
    {
        return $this->getOwnerRecord()->invoices()->getQuery();
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
        return InvoicesTable::configure($table, showClient: false);
    }
}
