<?php

namespace App\Filament\Client\Resources\Invoices\Pages;

use App\Filament\Client\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

/** The client's invoices, newest first. */
class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
