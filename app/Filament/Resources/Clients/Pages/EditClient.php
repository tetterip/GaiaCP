<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\Actions\ClientAreaActions;
use App\Filament\Resources\Clients\Actions\ClientCreditActions;
use App\Filament\Resources\Clients\Actions\ClientHintsAction;
use App\Filament\Resources\Clients\Actions\ReviewChangeRequestAction;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Widgets\ClientSummary;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Client;
use App\Support\ClientFields;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;

/**
 * The client page: a summary over tabs, the details form first, then the client's hosting, domains, other
 * services, orders, payment notices, payments and activity (each tab only for admins with its area). On a phone
 * the tabs become a select (`filament/clients/tabs-select`).
 */
class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReviewChangeRequestAction::make(),
            ClientAreaActions::make(),
            ClientCreditActions::make(),
            ClientHintsAction::make(),
            Action::make('newOrder')
                ->label('New order')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->url(fn (Client $record) => OrderResource::getUrl('create', ['client' => $record->getKey()])),
            // A client with services or credit keeps its history; terminate the services instead.
            DeleteAction::make()
                ->hidden(fn (Client $record) => $record->services()->exists() || $record->payments()->exists() || $record->creditTransactions()->exists()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Values of deleted client fields stay (Settings → Client fields)
        if (array_key_exists('custom_fields', $data)) {
            $data['custom_fields'] = array_merge($this->getRecord()->custom_fields ?? [], ClientFields::clean(ClientFields::all(), $data['custom_fields'])) ?: null;
        }

        return $data;
    }

    protected function getHeaderWidgets(): array
    {
        return [ClientSummary::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('Details');
    }

    public function getContentTabIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedIdentification;
    }

    public function getRelationManagersContentComponent(): Component
    {
        $tabs = parent::getRelationManagersContentComponent();

        if (! $tabs instanceof Tabs) {
            return $tabs;
        }

        return Group::make([
            View::make('filament.clients.tabs-select')
                ->viewData(['options' => $this->tabOptions()]),
            $tabs->extraAttributes(['class' => 'gcp-client-tabs']),
        ]);
    }

    /** @return array<string, string> tab key ('' = Details) => label, for the phone's select */
    public function tabOptions(): array
    {
        $options = ['' => __('Details')];

        foreach ($this->getCachedRelationManagers() as $key => $manager) {
            $class = $manager instanceof RelationManagerConfiguration ? $manager->relationManager : $manager;
            $badge = $class::getBadge($this->getRecord(), static::class);
            $title = $class::getTitle($this->getRecord(), static::class);

            $options[(string) $key] = filled($badge) ? "{$title} ({$badge})" : $title;
        }

        return $options;
    }
}
