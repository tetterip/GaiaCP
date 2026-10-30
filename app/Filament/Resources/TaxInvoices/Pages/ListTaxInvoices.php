<?php

namespace App\Filament\Resources\TaxInvoices\Pages;

use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use Filament\Resources\Pages\ListRecords;

/** Every invoice, newest first. */
class ListTaxInvoices extends ListRecords
{
    protected static string $resource = TaxInvoiceResource::class;
}
