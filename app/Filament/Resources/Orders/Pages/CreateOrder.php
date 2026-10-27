<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\PlaceOrder;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Service;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $placer = app(PlaceOrder::class);
        try {
            $order = $placer->handle(Client::findOrFail($data['client_id']), array_values($data['items']), $data['notes'] ?? null,
                coupon: filled($data['coupon_id'] ?? null) ? Coupon::find($data['coupon_id']) : null);
        } catch (ValidationException $e) {
            throw isset($e->errors()['coupon']) ? ValidationException::withMessages(['data.coupon_id' => $e->errors()['coupon']]) : $e;
        }

        foreach ($placer->setupErrors as $id => $error) {
            Notification::make()
                ->title(__(':item was not set up', ['item' => Service::find($id)?->label]))
                ->body($error.' '.__('Complete the order to retry.'))
                ->danger()
                ->persistent()
                ->send();
        }

        return $order;
    }

    protected function getRedirectUrl(): string
    {
        return OrderResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
