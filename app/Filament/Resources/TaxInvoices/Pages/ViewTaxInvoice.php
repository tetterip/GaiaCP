<?php

namespace App\Filament\Resources\TaxInvoices\Pages;

use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use Filament\Resources\Pages\ViewRecord;

/** One invoice, read only: it is never changed after it was issued. */
class ViewTaxInvoice extends ViewRecord
{
    protected static string $resource = TaxInvoiceResource::class;

    public function getTitle(): string
    {
        return __('Invoice :number', ['number' => $this->getRecord()->number]);
    }
}
