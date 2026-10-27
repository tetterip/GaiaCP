<?php

namespace App\Filament\Client\Resources\Payments\Pages;

use App\Filament\Client\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;
}
