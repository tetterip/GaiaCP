<?php

namespace App\Filament\Resources\Services\Pages;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Product;
use App\Models\Server;
use App\Support\ProductOptions;
use Filament\Resources\Pages\CreateRecord;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    /** Came from Compare with the server: back there once saved. */
    public ?int $fromServer = null;

    /** "Add to GaiaCP" on Servers → Compare with the server: an account already on the server, so it starts active (or suspended). */
    protected function afterFill(): void
    {
        $server = Server::query()->find(request()->integer('server'));

        if (! $server || blank(request()->query('username'))) {
            return;
        }

        $this->fromServer = $server->id;
        $domain = (string) request()->query('domain', '');

        $this->data = array_merge($this->data, [
            'type' => ServiceType::Hosting->value,
            'server_id' => $server->id,
            'username' => (string) request()->query('username'),
            'domain' => $domain,
            'label' => $domain,
            'package' => request()->query('package'),
            'status' => (request()->boolean('suspended') ? ServiceStatus::Suspended : ServiceStatus::Active)->value,
        ]);
    }

    /** What the service keeps of the options picked; its price already includes them. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $product = filled($data['product_id'] ?? null) ? Product::find($data['product_id']) : null;
        $cycle = $data['billing_cycle'] ?? BillingCycle::Annually;

        $data['options'] = isset($data['options']) ? (ProductOptions::select(
            $product,
            (array) $data['options'],
            $cycle instanceof BillingCycle ? $cycle : BillingCycle::from($cycle),
            $data['currency'] ?? null,
        ) ?: null) : null;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->fromServer
            ? ServerResource::getUrl('compare', ['record' => $this->fromServer])
            : parent::getRedirectUrl();
    }
}
