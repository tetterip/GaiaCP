<?php

namespace App\Filament\Client\Resources\Domains\Pages;

use App\Enums\ServiceStatus;
use App\Filament\Client\Concerns\HasStatusChips;
use App\Filament\Client\Pages\Order;
use App\Filament\Client\Resources\Domains\DomainResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListDomains extends ListRecords
{
    use HasStatusChips;

    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        // With nothing listed yet, the empty list's own button says it
        return [Order::callToAction(domain: true)->hidden(fn () => ! $this->chipsQuery()->exists())];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->statusChipsComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /**
     * All, one per status, Expiring soon, Auto renew off, then one per extension while the client has two or more
     * (.gr, .com…). Each chip sets every filter, so only one is on at a time.
     */
    protected function statusChipDefinitions(): array
    {
        $none = ['status' => ['value' => null], 'expiring' => ['isActive' => false], 'auto_renew_off' => ['isActive' => false], 'tld' => ['value' => null]];
        $chips = [];

        foreach ($this->chipsPerStatus(__('All'), [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending, ServiceStatus::Terminated]) as $key => $chip) {
            $chips[$key] = ['filters' => array_replace($none, $chip['filters'])] + $chip;
        }

        $chips['expiring'] = [
            'label' => __('Expiring soon'),
            'filters' => array_replace($none, ['expiring' => ['isActive' => true]]),
            'query' => fn (Builder $query) => DomainResource::expiringSoon($query),
            'dot' => 'warning',
        ];
        $chips['auto_renew_off'] = [
            'label' => __('Auto renew off'),
            'filters' => array_replace($none, ['auto_renew_off' => ['isActive' => true]]),
            'query' => fn (Builder $query) => DomainResource::autoRenewOff($query),
            'dot' => 'gray',
        ];

        $tlds = DomainResource::tlds($this->chipsQuery());

        if (count($tlds) > 1) {
            foreach ($tlds as $tld) {
                $chips['tld-'.$tld] = [
                    'label' => '.'.$tld,
                    'filters' => array_replace($none, ['tld' => ['value' => $tld]]),
                    'query' => fn (Builder $query) => DomainResource::withTld($query, $tld),
                ];
            }
        }

        return $chips;
    }

    protected function chipsQuery(): Builder
    {
        return DomainResource::getEloquentQuery();
    }
}
